<?php

namespace App\Services\Segment;

use App\DTOs\Segment\SegmentExportDTO;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Segments\Segment;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SegmentExportService
{
    private function clients(Segment $segment)
    {
        $paid = fn ($q) => $q->where('status', OrderStatus::DELIVERED)->where('payment_status', PaymentStatus::PAID);
        return $segment->clients()->with(['profile','tags'])
            ->withCount(['orders as export_orders_count'=>$paid])
            ->withSum(['orders as export_total_amount'=>$paid], 'total_amount')
            ->withAvg(['orders as export_average_check'=>$paid], 'total_amount')
            ->lazyById(500, 'clients.id', 'id');
    }

    /** International format only: no guessed country for a local number. */
    public function normalizePhone(?string $raw): ?string
    {
        $raw=trim($raw ?? '');
        if ($raw==='' || !preg_match('/^(?:\+|00)?[0-9(][0-9()\s.\-]*$/u', $raw)) return null;
        $digits=preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw,'00')) $digits=substr($digits,2);
        // Legacy Russian domestic prefix is unambiguous for eleven digits.
        if (strlen($digits)===11 && str_starts_with($digits,'8')) $digits='7'.substr($digits,1);
        return preg_match('/^[1-9][0-9]{9,14}$/', $digits) ? '+'.$digits : null;
    }

    private function rows(Segment $segment, SegmentExportDTO $dto, array &$skipped): \Generator
    {
        $seen=[];
        foreach ($this->clients($segment) as $client) {
            $reason=null; $contact=null;
            $manual=(bool)$client->pivot->is_manual;
            if ($dto->origin!=='all' && $manual!==($dto->origin==='manual')) $reason='origin';
            if (!$reason && $dto->channel==='email') {
                $contact=mb_strtolower(trim($client->email ?? ''));
                if (!filter_var($contact,FILTER_VALIDATE_EMAIL)) $reason='invalid_contact';
                elseif ($dto->consentOnly && !$client->subscribed_to_newsletter) $reason='no_subscription';
            }
            if (!$reason && $dto->channel==='whatsapp') {
                $contact=$this->normalizePhone($client->profile?->phone);
                if (!$contact) $reason='invalid_contact';
                elseif ($dto->consentOnly && !$client->subscribed_to_whatsapp) $reason='no_subscription';
            }
            if (!$reason && $dto->channel!=='all' && $dto->deduplicate) {
                if (isset($seen[$contact])) $reason='duplicate';
                else $seen[$contact]=true;
            }
            if ($reason) { $skipped[$reason]++; continue; }
            $data=[
                'id'=>$client->id,'full_name'=>$client->profile?->full_name ?? '',
                'email'=>$dto->channel==='email'?$contact:($client->email ?? ''),
                'phone'=>$dto->channel==='whatsapp'?$contact:($client->profile?->phone ?? ''),
                'birthday'=>$client->profile?->birthday ? \Carbon\Carbon::parse($client->profile->birthday)->toDateString() : '',
                'address'=>$client->profile?->address ?? '', 'registered_at'=>$client->created_at?->toDateString(),
                'orders_count'=>$client->export_orders_count,
                'total_amount'=>number_format((float)$client->export_total_amount,2,'.',''),
                'average_check'=>number_format((float)$client->export_average_check,2,'.',''),
                'segment_name'=>$segment->name, 'membership_source'=>$manual?'Вручную':'По условиям',
                'added_to_segment_at'=>$client->pivot->added_at,
                'subscribed_to_newsletter'=>$client->subscribed_to_newsletter?'Да':'Нет',
                'subscribed_to_whatsapp'=>$client->subscribed_to_whatsapp?'Да':'Нет',
                'personal_data_consent'=>$client->personal_data_consent?'Да':'Нет',
                'tags'=>$client->tags->pluck('name')->implode(', '),
            ];
            $row=[];
            foreach ($dto->columns as $column) $row[]=$this->safeCell($data[$column] ?? '',$column);
            yield $row;
        }
    }

    private function safeCell($value, string $column): string
    {
        $value=(string)$value;
        if ($column==='phone' && preg_match('/^\+[1-9][0-9]{9,14}$/',$value)) return $value;
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$value) ? "'".$value : $value;
    }

    public function preview(Segment $segment, SegmentExportDTO $dto): array
    {
        $skipped=['origin'=>0,'invalid_contact'=>0,'no_subscription'=>0,'duplicate'=>0];
        $preview=[];$count=0;
        foreach ($this->rows($segment,$dto,$skipped) as $row) {
            if ($count<10) $preview[]=$row;
            $count++;
        }
        return ['preview'=>$preview,'total_count'=>$count,'segment_count'=>$count+array_sum($skipped),
            'skipped'=>$skipped,'headers'=>$dto->getSelectedHeaders(),
            'last_recalculated_at'=>$segment->last_recalculated_at?->toIso8601String()];
    }

    public function exportToCSV(Segment $segment, SegmentExportDTO $dto): StreamedResponse
    {
        $name='segment_'.(Str::slug($segment->name) ?: $segment->id).'_'.$dto->channel.'_'.now()->format('Y-m-d_H-i-s').'.csv';
        return Response::stream(function () use ($segment,$dto) {
            $handle=fopen('php://output','w');fwrite($handle,"\xEF\xBB\xBF");
            fputcsv($handle,array_values($dto->getSelectedHeaders()),$dto->delimiter,'"','');
            $skipped=['origin'=>0,'invalid_contact'=>0,'no_subscription'=>0,'duplicate'=>0];
            foreach ($this->rows($segment,$dto,$skipped) as $row) fputcsv($handle,$row,$dto->delimiter,'"','');
            fclose($handle);
        },200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="'.$name.'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function canExport(Segment $segment): bool { return $segment->clients()->exists(); }
    public function getExportCount(Segment $segment): int { return $segment->clients()->count(); }
    public function exportToArray(Segment $segment, array $columns=[]): array
    {
        $skipped=['origin'=>0,'invalid_contact'=>0,'no_subscription'=>0,'duplicate'=>0];
        return iterator_to_array($this->rows($segment,new SegmentExportDTO($segment->id,$columns ?: SegmentExportDTO::DEFAULT_COLUMNS),$skipped),false);
    }
    public function getExportPreview(Segment $segment, array $columns=[]): array
    {
        return $this->preview($segment,new SegmentExportDTO($segment->id,$columns ?: SegmentExportDTO::DEFAULT_COLUMNS))['preview'];
    }
}
