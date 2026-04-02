<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI;

use Barnetik\Tbai\Fingerprint\PreviousInvoice;
use Barnetik\Tbai\Fingerprint\Vendor;
use Barnetik\Tbai\Invoice\Breakdown\NationalSubjectNotExemptBreakdownItem;
use Barnetik\Tbai\Invoice\Data;
use Barnetik\Tbai\Subject;
use Barnetik\Tbai\ValueObject\Amount;
use EBethus\LaravelTicketBAI\Exceptions\CertificateNotFoundException;
use EBethus\LaravelTicketBAI\Exceptions\InvalidTicketBAIDataException;
use EBethus\LaravelTicketBAI\Exceptions\InvalidTerritoryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TicketBAI
{
    public const TERRITORY_ARABA = 'ARABA';

    public const TERRITORY_BIZKAIA = 'BIZKAIA';

    public const TERRITORY_GIPUZKOA = 'GIPUZKOA';

    /** Code (01, 02, 03) => name. Single source of truth; codes and names both accepted in invoice() */
    private const CODE_TO_TERRITORY = [
        '01' => self::TERRITORY_ARABA,
        '02' => self::TERRITORY_BIZKAIA,
        '03' => self::TERRITORY_GIPUZKOA,
    ];

    protected ?Vendor $vendor = null;

    /** @var array<int, \Barnetik\Tbai\Invoice\Data\Detail> */
    protected array $items = [];

    protected ?string $certPassword = null;

    protected ?string $signedFilename = null;

    protected ?string $disk = null;

    protected ?string $invoiceNumber = null;

    protected ?int $idIssuer = null;

    protected ?float $totalInvoice = null;

    protected ?Invoice $model = null;

    protected ?Subject $subject = null;

    protected ?\Barnetik\Tbai\TicketBai $ticketbai = null;

    protected ?float $vatPerc = null;

    protected mixed $data = null;

    public function __construct(array $config = [])
    {
        if ($config !== []) {
            $license = $config['license'];
            $nif = $config['nif'];
            $appName = $config['appName'];
            $appVersion = $config['appVersion'];
            $this->certPassword = $config['certPassword'];
            $this->setVendor($license, $nif, $appName, $appVersion);

            if (! empty($config['disk'])) {
                $this->disk = $config['disk'];
            }
        }
    }

    /**
     * Format amount to proper decimal places (2 decimals by default for currency).
     * Prevents binary float precision issues when casting to string.
     */
    protected function formatAmount(float $amount, int $decimals = 2): string
    {
        return number_format($amount, $decimals, '.', '');
    }

    public function setVendor(string $license, string $nif, string $appName, string $appVersion): void
    {
        $this->vendor = new Vendor($license, $nif, $appName, $appVersion);
    }

    public function getDisk(): string
    {
        return $this->disk ?? 'local';
    }

    protected function getFingerprint(): \Barnetik\Tbai\Fingerprint
    {
        if ($this->vendor === null) {
            throw InvalidTicketBAIDataException::vendorNotSet();
        }

        $issuerColumn = Invoice::getColumnName('issuer');
        $createdAtColumn = Invoice::getColumnName('created_at');
        $prev = Invoice::where($issuerColumn, $this->idIssuer)
            ->orderBy($createdAtColumn, 'desc')
            ->first();

        $prevInvoice = null;
        if ($prev !== null) {
            $numberColumn = Invoice::getColumnName('number');
            $payload = Invoice::getTicketBaiPayload($prev);
            $signatureValue = $payload['signature'] ?? null;
            $createdAtValue = $prev->{$createdAtColumn};
            if ($createdAtValue instanceof \Carbon\Carbon) {
                $dateString = $createdAtValue->format('d-m-Y');
            } elseif ($createdAtValue !== null && $createdAtValue !== '') {
                $dateString = \Carbon\Carbon::parse($createdAtValue)->format('d-m-Y');
            } else {
                $dateString = date('d-m-Y');
            }

            $sentDate = new \Barnetik\Tbai\ValueObject\Date($dateString);
            // Only chain to previous invoice when we have a stored signature (required by TicketBAI spec)
            if ($signatureValue !== null && $signatureValue !== '') {
                $prevInvoice = new PreviousInvoice($prev->{$numberColumn}, $sentDate, (string) $signatureValue, null);
            }
        }

        return new \Barnetik\Tbai\Fingerprint($this->vendor, $prevInvoice);
    }

    public function issuer(string $nif, string $name, int $idIssuer, string $serie = ''): void
    {
        $this->idIssuer = $idIssuer;
        $issuer = new \Barnetik\Tbai\Subject\Issuer(new \Barnetik\Tbai\ValueObject\VatId($nif), $name);
        $recipient = null;
        $this->subject = new Subject($issuer, $recipient, Subject::ISSUED_BY_THIRD_PARTY);
    }

    protected function getInvoiceNumber(): string
    {
        if ($this->invoiceNumber === null) {
            $this->invoiceNumber = substr((string) Str::ulid(), 0, 20);
        }

        return $this->invoiceNumber;
    }

    protected function simplifyHeader(): \Barnetik\Tbai\Invoice\Header
    {
        $invoiceNumber = $this->getInvoiceNumber();
        $now = new \DateTime;
        $date = new \Barnetik\Tbai\ValueObject\Date($now->format('d-m-Y'));
        $time = new \Barnetik\Tbai\ValueObject\Time($now->format('H:i:s'));

        return \Barnetik\Tbai\Invoice\Header::createSimplified($invoiceNumber, $date, $time, '');
    }

    protected function getData(string $description): Data
    {
        if ($this->items === []) {
            throw InvalidTicketBAIDataException::noItemsPresent();
        }
        $this->totalInvoice = array_reduce(
            $this->items,
            function (float $a, $i): float {
                $amount = $i->toArray();

                return $a + (float) $amount['totalAmount'];
            },
            0.0
        );
        $data = new Data($description, new Amount($this->formatAmount($this->totalInvoice)), [Data::VAT_REGIME_01]);
        foreach ($this->items as $i) {
            $data->addDetail($i);
        }

        return $data;
    }

    public function setVat(float $vatPerc): void
    {
        $this->vatPerc = $vatPerc;
    }

    public function add(string $desc, float $unitPrice, float $q, ?float $discount = null): void
    {
        if ($this->vatPerc === null) {
            throw InvalidTicketBAIDataException::vatPercentageNotSet();
        }
        $unitAmount = new Amount($this->formatAmount($unitPrice * (100 - $this->vatPerc) / 100), 12, 8);
        $quantity = new Amount($this->formatAmount($q));
        $disc = $discount !== null ? new Amount($this->formatAmount($discount)) : null;
        $total = new Amount($this->formatAmount($unitPrice * $q - ($discount ?? 0)));
        $this->items[] = new \Barnetik\Tbai\Invoice\Data\Detail($desc, $unitAmount, $quantity, $total, $disc);
    }

    public function invoice(string $territory, string $description): string
    {
        $territory = strtoupper(trim($territory));
        if (isset(self::CODE_TO_TERRITORY[$territory])) {
            $territory = self::CODE_TO_TERRITORY[$territory];
        }
        if (! in_array($territory, array_values(self::CODE_TO_TERRITORY), true)) {
            throw InvalidTerritoryException::for($territory);
        }

        $data = $this->getData($description);
        $header = $this->simplifyHeader();
        $fingerprint = $this->getFingerprint();

        $totalInvoice = $this->totalInvoice;
        $vat = new Amount($this->formatAmount($this->vatPerc));
        $totalWithOutVat = $totalInvoice * (100 - $this->vatPerc) / 100;
        $vatDetail = new \Barnetik\Tbai\Invoice\Breakdown\VatDetail(
            new Amount($this->formatAmount($totalWithOutVat)),
            $vat,
            new Amount($this->formatAmount($totalInvoice - $totalWithOutVat))
        );
        $notExemptBreakdown = new NationalSubjectNotExemptBreakdownItem(
            NationalSubjectNotExemptBreakdownItem::NOT_EXEMPT_TYPE_S1,
            [$vatDetail]
        );
        $breakdown = new \Barnetik\Tbai\Invoice\Breakdown;
        $breakdown->addNationalSubjectNotExemptBreakdownItem($notExemptBreakdown);
        $invoice = new \Barnetik\Tbai\Invoice($header, $data, $breakdown);

        $territoryCode = (string) array_search($territory, self::CODE_TO_TERRITORY, true);
        $this->ticketbai = new \Barnetik\Tbai\TicketBai(
            $this->subject,
            $invoice,
            $fingerprint,
            $territoryCode,
            false
        );

        return $this->sign();
    }

    public function getCertificate(): \Barnetik\Tbai\PrivateKey
    {
        $path = trim(config('ticketbai.cert_path', 'certificado.p12'));
        $certFile = (is_string($path) && $path !== '' && (str_starts_with($path, '/') || (DIRECTORY_SEPARATOR === '\\' && strlen($path) >= 2 && $path[1] === ':')))
            ? $path
            : storage_path($path);

        if (! is_file($certFile) || ! is_readable($certFile)) {
            throw CertificateNotFoundException::atPath($certFile);
        }

        return \Barnetik\Tbai\PrivateKey::p12($certFile);
    }

    public function getCertPassword(): ?string
    {
        return $this->certPassword;
    }

    protected function sign(): string
    {
        $ticketbai = $this->ticketbai;
        $privateKey = $this->getCertificate();
        $certPassword = $this->certPassword ?? '';
        
        // Create file in system temp directory (will be moved to configured disk in save())
        $tempDir = sys_get_temp_dir();
        $this->signedFilename = $tempDir . DIRECTORY_SEPARATOR . 'ticketbai_' . $this->invoiceNumber . '.xml';
        
        $ticketbai->sign($privateKey, $certPassword, $this->signedFilename);
        $qr = new \Barnetik\Tbai\Qr($ticketbai, true);
        $qrURL = $qr->qrUrl();
        $this->save();

        return $qrURL;
    }

    public function save(): void
    {
        $this->model = new Invoice;
        $model = $this->model;
        $disk = Storage::disk($this->getDisk());

        $pathColumn = Invoice::getColumnName('path') ?? 'path';
        $issuerColumn = Invoice::getColumnName('issuer') ?? 'issuer';
        $numberColumn = Invoice::getColumnName('number') ?? 'number';
        $dataColumn = Invoice::getColumnName('data') ?? 'data';
        $dataKey = Invoice::getTicketBaiDataKey();

        // Save file to configured disk (respects S3, local, or any other disk)
        if ($this->signedFilename !== null && is_readable($this->signedFilename)) {
            $pathValue = $disk->putFile('ticketbai', new \Illuminate\Http\File($this->signedFilename));
        } else {
            throw InvalidTicketBAIDataException::missingSignedFile();
        }

        $attributes = [
            $pathColumn => $pathValue,
            $issuerColumn => $this->idIssuer,
            $numberColumn => $this->invoiceNumber,
        ];

        $baseData = is_array($this->data) ? $this->data : [];
        if ($this->ticketbai !== null) {
            $baseData[$dataKey] = [
                'signature' => $this->ticketbai->chainSignatureValue(),
                'territory' => $this->ticketbai->territory(),
            ];
        }
        $attributes[$dataColumn] = $baseData;

        $model->fill($attributes);
        $model->save();
        $this->clearFile();
        Job\InvoiceSend::dispatch($model);
    }

    public function copySignatureOnLocal(): void
    {
        if ($this->model === null || $this->signedFilename === null) {
            return;
        }
        $payload = Invoice::getTicketBaiPayload($this->model);
        $path = $payload['path'] ?? null;
        if ($path === null) {
            $pathColumn = Invoice::getColumnName('path');
            if ($pathColumn !== null) {
                $path = $this->model->{$pathColumn};
            }
        }
        if ($path === null) {
            return;
        }
        $disk = Storage::disk($this->getDisk());
        file_put_contents($this->signedFilename, $disk->get($path));
    }

    public function getModel(): ?Invoice
    {
        return $this->model;
    }

    public function getTBAI(): ?\Barnetik\Tbai\TicketBai
    {
        return $this->ticketbai;
    }

    public function data(mixed $data): void
    {
        $this->data = $data;
    }

    public function clearFile(): void
    {
        if ($this->signedFilename !== null && is_readable($this->signedFilename)) {
            unlink($this->signedFilename);
        }
    }
}
