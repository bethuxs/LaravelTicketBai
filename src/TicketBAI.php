<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI;

use Barnetik\Tbai\Fingerprint\Vendor;
use Barnetik\Tbai\Fingerprint\PreviousInvoice;
use Barnetik\Tbai\Invoice\Breakdown\NationalSubjectNotExemptBreakdownItem;
use Barnetik\Tbai\Invoice\Data;
use Barnetik\Tbai\Subject;
use Barnetik\Tbai\ValueObject\Amount;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TicketBAI
{
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

            if (!empty($config['disk'])) {
                $this->disk = $config['disk'];
            }
        }
    }

    public function setVendor(string $license, string $nif, string $appName, string $appVersion): void
    {
        $this->vendor = new Vendor($license, $nif, $appName, $appVersion);
    }

    protected function getFingerprint(): \Barnetik\Tbai\Fingerprint
    {
        if ($this->vendor === null) {
            throw new \RuntimeException('Vendor not set');
        }

        $issuerColumn = Invoice::getColumnName('issuer');
        $createdAtColumn = Invoice::getColumnName('created_at');
        $prev = Invoice::where($issuerColumn, $this->idIssuer)
            ->orderBy($createdAtColumn, 'desc')
            ->first();

        $prevInvoice = null;
        if ($prev !== null) {
            $numberColumn = Invoice::getColumnName('number');
            $signatureColumn = Invoice::getColumnName('signature');
            $createdAtValue = $prev->{$createdAtColumn};
            if ($createdAtValue instanceof \Carbon\Carbon) {
                $dateString = $createdAtValue->format('d-m-Y');
            } elseif ($createdAtValue !== null && $createdAtValue !== '') {
                $dateString = \Carbon\Carbon::parse($createdAtValue)->format('d-m-Y');
            } else {
                $dateString = date('d-m-Y');
            }

            $sentDate = new \Barnetik\Tbai\ValueObject\Date($dateString);
            $signatureValue = null;
            if ($signatureColumn !== null) {
                $signatureValue = $prev->{$signatureColumn} ?? null;
            }
            $prevInvoice = new PreviousInvoice($prev->{$numberColumn}, $sentDate, $signatureValue, null);
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
        $this->invoiceNumber = (string) Str::ulid();
        return $this->invoiceNumber;
    }

    protected function simplifyHeader(): \Barnetik\Tbai\Invoice\Header
    {
        $invoiceNumber = $this->getInvoiceNumber();
        $now = new \DateTime();
        $date = new \Barnetik\Tbai\ValueObject\Date($now->format('d-m-Y'));
        $time = new \Barnetik\Tbai\ValueObject\Time($now->format('H:i:s'));
        return \Barnetik\Tbai\Invoice\Header::createSimplified($invoiceNumber, $date, $time, '');
    }

    protected function getData(string $description): Data
    {
        if ($this->items === []) {
            throw new \RuntimeException('Not item present');
        }
        $this->totalInvoice = array_reduce(
            $this->items,
            function (float $a, $i): float {
                $amount = $i->toArray();
                return $a + (float) $amount['totalAmount'];
            },
            0.0
        );
        $data = new Data($description, new Amount((string) $this->totalInvoice), [Data::VAT_REGIME_01]);
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
            throw new \RuntimeException('VAT percentage not set');
        }
        $unitAmount = new Amount((string) ($unitPrice * (100 - $this->vatPerc) / 100), 12, 8);
        $quantity = new Amount((string) $q);
        $disc = $discount !== null ? new Amount((string) $discount) : null;
        $total = new Amount((string) ($unitPrice * $q - ($discount ?? 0)));
        $this->items[] = new \Barnetik\Tbai\Invoice\Data\Detail($desc, $unitAmount, $quantity, $total, $disc);
    }

    public function invoice(string $territory, string $description): string
    {
        $data = $this->getData($description);
        $header = $this->simplifyHeader();
        $fingerprint = $this->getFingerprint();

        $totalInvoice = $this->totalInvoice;
        $vat = new Amount((string) $this->vatPerc);
        $totalWithOutVat = $totalInvoice * (100 - $this->vatPerc) / 100;
        $vatDetail = new \Barnetik\Tbai\Invoice\Breakdown\VatDetail(
            new Amount((string) $totalWithOutVat),
            $vat,
            new Amount((string) ($totalInvoice - $totalWithOutVat))
        );
        $notExemptBreakdown = new NationalSubjectNotExemptBreakdownItem(
            NationalSubjectNotExemptBreakdownItem::NOT_EXEMPT_TYPE_S1,
            [$vatDetail]
        );
        $breakdown = new \Barnetik\Tbai\Invoice\Breakdown();
        $breakdown->addNationalSubjectNotExemptBreakdownItem($notExemptBreakdown);
        $invoice = new \Barnetik\Tbai\Invoice($header, $data, $breakdown);

        $this->ticketbai = new \Barnetik\Tbai\TicketBai(
            $this->subject,
            $invoice,
            $fingerprint,
            $territory,
            false
        );

        return $this->sign();
    }

    public function getCertificate(): \Barnetik\Tbai\PrivateKey
    {
        $path = config('ticketbai.cert_path', 'certificado.p12');
        $certFile = (is_string($path) && $path !== '' && (str_starts_with($path, '/') || (DIRECTORY_SEPARATOR === '\\' && strlen($path) >= 2 && $path[1] === ':')))
            ? $path
            : storage_path($path);
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
        $this->signedFilename = storage_path('ticketbai' . $this->invoiceNumber . '.xml');
        \Illuminate\Support\Facades\Log::debug('Signed file: ' . $this->signedFilename);
        $ticketbai->sign($privateKey, $certPassword, $this->signedFilename);
        $qr = new \Barnetik\Tbai\Qr($ticketbai, true);
        $qrURL = $qr->qrUrl();
        $this->save();
        return $qrURL;
    }

    public function save(): void
    {
        $this->model = new Invoice();
        $model = $this->model;
        \Illuminate\Support\Facades\Log::debug($this->signedFilename ?? '');
        $disk = Storage::disk($this->disk ?? 'local');

        $pathColumn = Invoice::getColumnName('path') ?? 'path';
        $issuerColumn = Invoice::getColumnName('issuer') ?? 'issuer';
        $numberColumn = Invoice::getColumnName('number') ?? 'number';
        $signatureColumn = Invoice::getColumnName('signature');
        $dataColumn = Invoice::getColumnName('data');

        $attributes = [
            $pathColumn => $disk->putFile('ticketbai', new \Illuminate\Http\File($this->signedFilename)),
            $issuerColumn => $this->idIssuer,
            $numberColumn => $this->invoiceNumber,
        ];

        if ($signatureColumn !== null && $signatureColumn !== '' && $this->ticketbai !== null) {
            $attributes[$signatureColumn] = $this->ticketbai->signatureValue();
        }

        if ($dataColumn !== null && $dataColumn !== '' && $this->data !== null) {
            $attributes[$dataColumn] = $this->data;
        }

        $model->fill($attributes);
        $model->save();
        $this->clearFile();
        Job\InvoiceSend::dispatch($this);
    }

    public function copySignatureOnLocal(): void
    {
        $disk = Storage::disk($this->disk ?? 'local');
        $pathColumn = Invoice::getColumnName('path');
        if ($pathColumn === null || $this->model === null || $this->signedFilename === null) {
            return;
        }
        file_put_contents($this->signedFilename, $disk->get($this->model->{$pathColumn}));
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
