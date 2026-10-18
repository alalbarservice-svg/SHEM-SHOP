<?php
require __DIR__.'/domain.php';
define('ARRAY_A','ARRAY_A');
function current_time($f,$utc=false){return '2026-10-16 12:00:00';}
function wp_create_nonce($s){return 'test-nonce';}
function admin_url($s){return 'https://example.test/wp-admin/'.$s;}
function add_query_arg($a,$u){return $u.'?'.http_build_query($a);}
function wp_is_uuid($s){return preg_match('/^[a-f0-9-]{36}$/i',$s);}
function absint($n){return abs((int)$n);}
function current_user_can($cap){return $GLOBALS['permission']??false;}
class FakeDB {
 public $prefix='wp_',$rows=[],$insert_id=0,$next=7001,$fail=null,$snapshot=null;
 function prepare($sql,...$args){return [$sql,$args];}
 function get_var($q){if(is_string($q)&&str_contains($q,'SELECT next_number'))return $this->next;if(is_string($q)&&str_contains($q,'max_allowed_packet'))return 67108864;return 1;}
 function get_row($q,$mode){[$sql,$args]=$q;foreach($this->rows as $r){if(str_contains($sql,'source_key=')&&$r['source_key']===$args[0])return $r;if(!str_contains($sql,'source_key=')&&$r['id']==$args[0]&&!$r['deleted'])return $r;}return null;}
 public $audit=[];function insert($t,$r){if(str_ends_with($t,'_audit')){$this->audit[]=$r;$this->insert_id=count($this->audit);return 1;}$id=$r['id']??$this->next;$this->insert_id=$id;$this->rows[$id]=array_merge(['id'=>$id,'version'=>1,'deleted'=>0],$r);return 1;}
 function update($t,$data,$where){if(str_ends_with($t,'_sequence')){$this->next=$data['next_number'];return 1;}foreach($data as $k=>$v)$this->rows[$where['id']][$k]=$v;return 1;}
 function query($q){if(is_string($q)){if($q==='START TRANSACTION'){if($this->fail==='start')return false;$this->snapshot=[$this->rows,$this->next];}if($q==='COMMIT'){if($this->fail==='commit')return false;$this->snapshot=null;}if($q==='ROLLBACK'&&$this->snapshot){[$this->rows,$this->next]=$this->snapshot;$this->snapshot=null;}return 1;}[$sql,$a]=$q;if(str_contains($sql,'version=version+1')){[$json,$pdf,$now,$id,$version]=$a;if($this->rows[$id]['version']!=$version||$this->rows[$id]['deleted'])return 0;$this->rows[$id]['payload']=$json;$this->rows[$id]['pdf']=$pdf;$this->rows[$id]['version']++;return 1;}if(str_contains($sql,'deleted=1')){[$now,$id,$version]=$a;if($this->rows[$id]['version']!=$version)return 0;$this->rows[$id]['deleted']=1;$this->rows[$id]['payload']=null;$this->rows[$id]['pdf']=null;return 1;}return 0;}
}
class Request implements ArrayAccess {function __construct(public $body,public $id=0){}function get_json_params(){return $this->body;}function offsetGet($o):mixed{return $this->id;}function offsetExists($o):bool{return true;}function offsetSet($o,$v):void{}function offsetUnset($o):void{}}
$wpdb=new FakeDB();$p['items']=[['name'=>'בדיקת ארכיון','sku'=>'TEST','qty'=>1,'price'=>118]];$p=ShemDok::clean($p);
$a=ShemDok::issue($p,'test-event');check($a['id']===7001,'first sequence starts 7001');$b=ShemDok::issue($p,'test-event');check($b['id']===7001&&count($wpdb->rows)===1,'duplicate event returns existing document');$c=ShemDok::issue($p,'second-event');check($c['id']===7002,'shared sequence increments');
$p['customer']='שם לאחר עריכה';rejects(fn()=>ShemDok::request('document/(?P<id>\\d+)/save',new Request(['document'=>$p,'version'=>1],7001)),'issued document cannot be edited (immutable, 410)');
rejects(fn()=>ShemDok::request('document/(?P<id>\\d+)/delete',new Request(['version'=>1],7001)),'issued document cannot be deleted (immutable, 410)');check($wpdb->rows[7001]['payload']!==null&&$wpdb->rows[7001]['pdf']!==null&&$wpdb->rows[7001]['version']===1,'issued document content untouched after edit and delete attempts');$again=ShemDok::issue($p,'test-event');check($again['id']===7001&&!empty($again['existing']),'same event still returns the existing document');$d=ShemDok::issue($p,'third-event');check($d['id']===7003,'numbers stay sequential');check(!ShemDok::allowed(),'unauthorized management denied');$GLOBALS['permission']=true;check(ShemDok::allowed(),'management capability accepted');

foreach(['start','commit'] as $failure){$wpdb->fail=$failure;$next=$wpdb->next;$count=count($wpdb->rows);rejects(fn()=>ShemDok::issue($p,'failed-'.$failure),'transaction '.$failure.' failure reported');check($wpdb->next===$next&&count($wpdb->rows)===$count,'failed transaction does not consume number or leave row');}$wpdb->fail=null;
