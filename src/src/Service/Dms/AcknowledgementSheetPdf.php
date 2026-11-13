<?php

namespace App\Service\Dms;

use App\Entity\AcknowledgementRecipient;
use App\Entity\AcknowledgementRequest;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The acknowledgement sheet: which exact version (number and SHA-256) people were asked to read, and per person the
 * unit and position they were asked under, the status and the time of their electronic confirmation. A blank
 * signature column lets the sheet also be signed on paper. Rendered without remote resources; values are escaped.
 */
final class AcknowledgementSheetPdf
{
    private const STATUS_LABELS = [
        AcknowledgementRecipient::PENDING => 'Not opened',
        AcknowledgementRecipient::VIEWED => 'Opened, not confirmed',
        AcknowledgementRecipient::ACKNOWLEDGED => 'Acknowledged',
        AcknowledgementRecipient::EXCUSED => 'Excused',
    ];

    /** @param list<AcknowledgementRecipient> $recipients */
    public function render(AcknowledgementRequest $request, array $recipients, bool $partial): string
    {
        $document = $request->getDocument();
        $today = new \DateTimeImmutable('today');
        $counts = AcknowledgementViews::counts($recipients, $today);
        $facts = [
            'Document' => $document->getTitle(),
            'Registration number' => $document->getRegistrationNumber() ?? '—',
            'Type' => $document->getType()->getName(),
            'Version' => 'v'.$request->getVersionNumber(),
            'Sent by' => $request->getSenderEmail().', '.$request->getCreatedAt()->format('Y-m-d H:i'),
            'Deadline' => $request->getDueOn()?->format('Y-m-d') ?? 'None',
            'Status' => $request->isOpen() ? 'Open' : 'Closed '.$request->getClosedAt()?->format('Y-m-d H:i'),
            'Completion' => sprintf('%d%% — %d of %d acknowledged, %d excused, %d outstanding', $counts['completion_percent'], $counts['acknowledged'], $counts['total'], $counts['excused'], $counts['open']),
        ];
        $factRows = '';
        foreach ($facts as $label => $value) {
            $factRows .= '<tr><th>'.$this->e($label).'</th><td>'.$this->e($value).'</td></tr>';
        }
        $rows = '';
        foreach (array_values($recipients) as $index => $recipient) {
            $status = self::STATUS_LABELS[$recipient->getStatus()];
            if ($recipient->isOverdue($today)) {
                $status .= ' (overdue)';
            }
            if ($recipient->getStatus() === AcknowledgementRecipient::EXCUSED) {
                $status .= ': '.$recipient->getExcuseReason();
            }
            $confirmed = $recipient->getAcknowledgedAt() !== null ? $recipient->getAcknowledgedAt()->format('Y-m-d H:i').($recipient->isLate() ? ' (late)' : '') : '';
            $rows .= '<tr><td>'.($index + 1).'</td><td>'.$this->e($recipient->getUserEmail()).'</td><td>'.$this->e($recipient->getPositionTitle() ?? '—').'<br><span class="muted">'.$this->e($recipient->getUnitName() ?? '—').'</span></td>'
                .'<td>'.$this->e($status).'</td><td>'.$this->e($confirmed).'</td><td class="sign"></td></tr>';
        }
        $body = '<p class="state">Acknowledgement sheet'.($partial ? ' — your units only' : '').'</p>'
            .'<h1>'.$this->e($document->getTitle()).'</h1>'
            .($request->getMessage() !== null ? '<p>'.nl2br($this->e($request->getMessage())).'</p>' : '')
            .'<table class="facts">'.$factRows.'</table>'
            .'<p class="hash">SHA-256 of the version to read: '.$this->e($request->getVersionSha256()).'</p>'
            .'<table><thead><tr><th>#</th><th>Employee</th><th>Position / unit</th><th>Status</th><th>Confirmed electronically</th><th>Signature</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p class="foot">Generated '.(new \DateTimeImmutable())->format('Y-m-d H:i').'. Each electronic confirmation refers to the version with the SHA-256 above. Units and positions are as recorded when each person was asked.</p>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;color:#202b29;font-size:10px}h1{color:#176b63;font-size:20px;margin:4px 0 8px}
            .state{color:#b26a3e;font-weight:bold;text-transform:uppercase;margin:0}.muted{color:#68756f}
            table{width:100%;border-collapse:collapse;margin-top:10px}th,td{padding:5px;border-bottom:1px solid #dce0d8;text-align:left;vertical-align:top}th{background:#edf1eb;color:#53645e}
            .facts th{width:26%}.hash{font-family:DejaVu Sans Mono,monospace;font-size:8px;word-break:break-all;margin:8px 0}.sign{width:18%}.foot{margin-top:18px;color:#68756f;font-size:8px}
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
