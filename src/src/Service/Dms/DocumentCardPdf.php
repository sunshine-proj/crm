<?php

namespace App\Service\Dms;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\DocumentVersion;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Registration card: the document's identity (number, dates, owner), the exact file versions with their SHA-256
 * and the trail of actions. Phase 3 adds the approval sheet and Phase 4 the acknowledgement sheet to this output.
 * Rendered without remote resources; every value is escaped.
 */
final class DocumentCardPdf
{
    /**
     * @param list<DocumentVersion> $versions newest first
     * @param list<DocumentEvent> $events oldest first
     */
    public function render(Document $document, array $versions, array $events): string
    {
        $facts = [
            'Type' => $document->getType()->getName(),
            'Status' => ucfirst(str_replace('_', ' ', $document->getStatus())),
            'Registered' => $document->getRegisteredAt() !== null ? $document->getRegisteredAt()->format('Y-m-d H:i').' by '.$document->getRegisteredBy() : 'Not registered',
            'Author' => $document->getAuthor()->getUserIdentifier(),
            'Owner unit' => $document->getOwnerUnit()?->getName() ?? '—',
            'Confidentiality' => ucfirst($document->getConfidentiality()),
            'Effective' => $document->getEffectiveOn()?->format('Y-m-d') ?? '—',
            'Expires' => $document->getExpiresOn()?->format('Y-m-d') ?? '—',
            'Review' => $document->getReviewOn()?->format('Y-m-d') ?? '—',
        ];
        $factRows = '';
        foreach ($facts as $label => $value) {
            $factRows .= '<tr><th>'.$this->e($label).'</th><td>'.$this->e($value).'</td></tr>';
        }
        $versionRows = '';
        foreach ($versions as $version) {
            $versionRows .= '<tr><td>v'.$version->getNumber().'</td><td>'.$this->e($version->getOriginalName()).'</td><td>'.$this->e($version->getUploadedByEmail()).'<br>'
                .$version->getCreatedAt()->format('Y-m-d H:i').'</td><td class="hash">'.$this->e($version->getSha256()).'</td></tr>';
        }
        $eventRows = '';
        foreach ($events as $event) {
            $eventRows .= '<tr><td>'.$event->getCreatedAt()->format('Y-m-d H:i').'</td><td>'.$this->e(str_replace('_', ' ', $event->getAction())).'</td><td>'.$this->e($event->getActor()).'</td></tr>';
        }
        $number = $document->getRegistrationNumber();
        $body = '<p class="state">'.($number !== null ? 'Registration card' : 'Draft — not registered').'</p>'
            .'<h1>'.$this->e($document->getTitle()).'</h1>'
            .'<p class="number">'.$this->e($number ?? 'No registration number').'</p>'
            .($document->getSummary() !== null ? '<p>'.nl2br($this->e($document->getSummary())).'</p>' : '')
            .'<table class="facts">'.$factRows.'</table>'
            .'<h2>Versions</h2><table><thead><tr><th>#</th><th>File</th><th>Uploaded</th><th>SHA-256</th></tr></thead><tbody>'.$versionRows.'</tbody></table>'
            .'<h2>History</h2><table><thead><tr><th>When</th><th>Action</th><th>By</th></tr></thead><tbody>'.$eventRows.'</tbody></table>'
            .'<p class="foot">Generated '.(new \DateTimeImmutable())->format('Y-m-d H:i').'. The SHA-256 identifies the exact file content of each version.</p>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;color:#202b29;font-size:11px}h1{color:#176b63;font-size:22px;margin:4px 0}h2{font-size:14px;margin:22px 0 6px;color:#176b63}
            .state{color:#b26a3e;font-weight:bold;text-transform:uppercase;margin:0}.number{font-size:15px;font-weight:bold;margin:0 0 12px}
            table{width:100%;border-collapse:collapse}th,td{padding:6px;border-bottom:1px solid #dce0d8;text-align:left;vertical-align:top}th{background:#edf1eb;color:#53645e}
            .facts th{width:28%}.hash{font-family:DejaVu Sans Mono,monospace;font-size:8px;word-break:break-all}.foot{margin-top:20px;color:#68756f;font-size:9px}
            </style></head><body>'.$body.'</body></html>', 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
