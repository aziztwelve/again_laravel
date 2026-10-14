<?php
namespace Tests\Feature\Segment;

use App\DTOs\Segment\SegmentExportDTO;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Segments\Segment;
use App\Models\User;
use App\Services\Segment\SegmentExportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SegmentExportTest extends TestCase
{
    use DatabaseTransactions;
    private function segment(): Segment { return Segment::create(['name'=>'Экспорт тест '.uniqid(),'is_active'=>true,'recalculate_frequency'=>'manual']); }
    private function client(Segment $s, array $attrs=[], string $phone='+79000000001', bool $manual=false): Client
    {
        $c=Client::factory()->create(array_merge(['email'=>'test'.uniqid().'@example.com','subscribed_to_newsletter'=>true,'subscribed_to_whatsapp'=>true],$attrs));
        $c->profile()->create(['first_name'=>'Иван','last_name'=>'Тест','phone'=>$phone,'address'=>'Адрес; с запятой, и "кавычкой"']);
        $s->clients()->attach($c->id,['added_at'=>now(),'is_manual'=>$manual]);return $c;
    }
    private function preview(Segment $s,array $data=[]): array { return app(SegmentExportService::class)->preview($s,SegmentExportDTO::fromRequest($s->id,$data)); }
    private function csv(Segment $s,array $data=[]): array
    {
        $response=app(SegmentExportService::class)->exportToCSV($s,SegmentExportDTO::fromRequest($s->id,$data));
        ob_start();$response->sendContent();$text=ob_get_clean();
        $this->assertStringStartsWith("\xEF\xBB\xBF",$text);
        $file=fopen('php://temp','w+');fwrite($file,substr($text,3));rewind($file);$rows=[];
        while(($row=fgetcsv($file,0,$data['delimiter']??';','"',''))!==false)$rows[]=$row;
        fclose($file);return $rows;
    }
    public function test_column_order_unicode_quotes_delimiters_and_preview_equal_csv(): void
    {
        $s=$this->segment();$c=$this->client($s);
        foreach([';',','] as $delimiter) {
            $data=['columns'=>['address','email','full_name','id'],'delimiter'=>$delimiter];
            $preview=$this->preview($s,$data);$csv=$this->csv($s,$data);
            $this->assertSame(['Адрес','Email','ФИО','ID'],$csv[0]);
            $this->assertSame($preview['preview'][0],$csv[1]);
            $this->assertSame($c->profile->address,$csv[1][0]);
        }
    }
    public function test_delivered_paid_orders_count_excludes_cancelled_unpaid_and_deleted(): void
    {
        $s=$this->segment();$c=$this->client($s);
        foreach([1000,3000] as $total)Order::factory()->create(['client_id'=>$c->id,'status'=>OrderStatus::DELIVERED,'payment_status'=>PaymentStatus::PAID,'total_amount'=>$total]);
        Order::factory()->create(['client_id'=>$c->id,'status'=>OrderStatus::CANCELLED,'payment_status'=>PaymentStatus::PAID,'total_amount'=>9000]);
        Order::factory()->create(['client_id'=>$c->id,'status'=>OrderStatus::DELIVERED,'payment_status'=>PaymentStatus::PENDING,'total_amount'=>8000]);
        $deleted=Order::factory()->create(['client_id'=>$c->id,'status'=>OrderStatus::DELIVERED,'payment_status'=>PaymentStatus::PAID,'total_amount'=>7000]);$deleted->delete();
        $this->assertSame(['2','4000.00','2000.00'],$this->preview($s,['columns'=>['orders_count','total_amount','average_check']])['preview'][0]);
    }
    public function test_email_filters_invalid_unsubscribed_and_case_insensitive_duplicates(): void
    {
        $s=$this->segment();$this->client($s,['email'=>'ANNA@example.com']);$this->client($s,['email'=>'anna@example.com']);
        $this->client($s,['email'=>null]);$this->client($s,['email'=>'bad-address']);$this->client($s,['subscribed_to_newsletter'=>false]);
        $p=$this->preview($s,['channel'=>'email']);
        $this->assertSame(1,$p['total_count']);$this->assertSame(5,$p['segment_count']);
        $this->assertSame(['origin'=>0,'invalid_contact'=>2,'no_subscription'=>1,'duplicate'=>1],$p['skipped']);
        $this->assertSame('anna@example.com',$p['preview'][0][2]);
        $this->assertSame(3,$this->preview($s,['channel'=>'email','consent_only'=>false,'deduplicate'=>false])['total_count']);
    }
    public function test_whatsapp_uses_separate_subscription_and_normalized_phone_duplicates(): void
    {
        $s=$this->segment();$this->client($s,[], '8 (900) 000-00-01');$this->client($s,[],'+7 900 000 00 01');
        $this->client($s,['subscribed_to_whatsapp'=>false,'messenger_subscription'=>true],'+79000000002');
        $this->client($s,[],'123');$p=$this->preview($s,['channel'=>'whatsapp']);
        $this->assertSame(1,$p['total_count']);$this->assertSame('+79000000001',$p['preview'][0][2]);
        $this->assertSame(1,$p['skipped']['no_subscription']);$this->assertSame(1,$p['skipped']['duplicate']);$this->assertSame(1,$p['skipped']['invalid_contact']);
    }
    public static function phoneCases(): array { return [['+7 (900) 000-00-01','+79000000001'],['89000000001','+79000000001'],['00351912345678','+351912345678'],['+351912345678','+351912345678'],['123',null],['',null],['+7+9000000001',null],['phone79000000001',null],['00000000000',null],['+1234567890123456',null]]; }
    #[DataProvider('phoneCases')]
    public function test_phone_normalization(string $phone,?string $expected): void { $this->assertSame($expected,app(SegmentExportService::class)->normalizePhone($phone)); }
    public function test_full_export_keeps_duplicates_missing_contacts_and_both_origins(): void
    {
        $s=$this->segment();$this->client($s,['email'=>null,'subscribed_to_newsletter'=>false], '',true);$this->client($s,[], '+79000000001');$this->client($s,[], '+79000000001');
        $p=$this->preview($s,['channel'=>'all','consent_only'=>true]);$this->assertSame(3,$p['total_count']);
        $this->assertSame(1,$this->preview($s,['origin'=>'manual'])['total_count']);$this->assertSame(2,$this->preview($s,['origin'=>'auto'])['total_count']);
    }
    public function test_no_profile_no_orders_empty_segment_and_deleted_client(): void
    {
        $s=$this->segment();$this->assertSame(0,$this->preview($s)['total_count']);
        $c=Client::factory()->create();$s->clients()->attach($c->id,['added_at'=>now()]);
        $this->assertSame(['','0','0.00','0.00'],$this->preview($s,['columns'=>['phone','orders_count','total_amount','average_check']])['preview'][0]);
        $c->delete();$this->assertSame(0,$this->preview($s)['total_count']);
    }
    public function test_preview_limit_is_ten_but_csv_exports_all_rows(): void
    {
        $s=$this->segment();for($i=0;$i<13;$i++)$this->client($s);
        $p=$this->preview($s,['columns'=>['id']]);$this->assertSame(13,$p['total_count']);$this->assertCount(10,$p['preview']);$this->assertCount(14,$this->csv($s,['columns'=>['id']]));
    }
    public function test_spreadsheet_formula_payloads_are_neutralized(): void
    {
        $s=$this->segment();$c=$this->client($s);$c->profile->update(['first_name'=>'=HYPERLINK("x")','last_name'=>'','address'=>' @SUM(1;2)']);
        $row=$this->csv($s,['columns'=>['full_name','address']])[1];$this->assertStringStartsWith("'=",$row[0]);$this->assertStringStartsWith("' @",$row[1]);
    }
    public function test_export_and_preview_require_staff_and_validate_sensitive_columns(): void
    {
        $s=$this->segment();$c=$this->client($s);
        foreach(['export','export-preview'] as $endpoint) {
            $url='/api/segments/'.$s->id.'/'.$endpoint;
            $this->getJson($url)->assertUnauthorized();Sanctum::actingAs($c);$this->getJson($url)->assertForbidden();
            Sanctum::actingAs(User::factory()->create());
            foreach([['columns'=>[]],['columns'=>['email','email']],['columns'=>['password']],['channel'=>'invalid'],['delimiter'=>'|'],['channel'=>'email','columns'=>['id']]] as $data)$this->json('GET',$url,$data)->assertUnprocessable();
            $this->getJson($url.'?'.http_build_query(['channel'=>'email']))->assertOk();
            auth()->forgetGuards();
        }
    }
    public function test_export_queries_do_not_grow_per_client(): void
    {
        $s=$this->segment();for($i=0;$i<15;$i++)$this->client($s);
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        try {
            $this->assertSame(15,$this->preview($s)['total_count']);
            $this->assertLessThanOrEqual(8,count(\Illuminate\Support\Facades\DB::getQueryLog()));
        } finally { \Illuminate\Support\Facades\DB::disableQueryLog(); }
    }

    public function test_email_subscription_does_not_imply_whatsapp_subscription(): void
    {
        $s=$this->segment();$this->client($s,['subscribed_to_newsletter'=>true,'subscribed_to_whatsapp'=>false]);
        $this->assertSame(1,$this->preview($s,['channel'=>'email'])['total_count']);$this->assertSame(0,$this->preview($s,['channel'=>'whatsapp'])['total_count']);
    }
}
