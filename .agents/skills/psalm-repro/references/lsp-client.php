<?php
// usage: php client.php <psalm-language-server> <root> <scenario>
[$_, $server, $root, $scenario] = $argv;
$file = $root . '/src/A.php'; $uri = 'file://' . $file;
$desc=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$p=proc_open(['php8.4',$server,'--root='.$root,'-c',$root.'/psalm.xml','--use-ini-defaults'],$desc,$pipes,'/tmp');
stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false); $buf='';
function send($m){ global $pipes; $j=json_encode($m); fwrite($pipes[0],"Content-Length: ".strlen($j)."\r\n\r\n".$j); }
function rd($secs){ global $pipes,$buf; $end=microtime(true)+$secs; $out=[]; while(microtime(true)<$end){ $c=fread($pipes[1],65536); if($c!==''&&$c!==false){$buf.=$c;} else usleep(50000);
 while(preg_match('/Content-Length: (\d+)\r\n\r\n/',$buf,$m,PREG_OFFSET_CAPTURE)){ $len=(int)$m[1][0]; $st=$m[0][1]+strlen($m[0][0]); if(strlen($buf)<$st+$len)break; $out[]=json_decode(substr($buf,$st,$len),true); $buf=substr($buf,$st+$len);} } return $out; }
function diags($msgs,$uri){ $last=null; foreach($msgs as $m){ if(($m['method']??'')==='textDocument/publishDiagnostics' && $m['params']['uri']===$uri){ $last=array_map(fn($d)=>($d['code']??'?').'@L'.$d['range']['start']['line'].'-'.$d['range']['end']['line'],$m['params']['diagnostics']); } } return $last===null?'(no publish)':json_encode($last); }
$ver=1; $text=file_get_contents($file);
function open($text){ global $uri; send(['jsonrpc'=>'2.0','method'=>'textDocument/didOpen','params'=>['textDocument'=>['uri'=>$uri,'languageId'=>'php','version'=>1,'text'=>$text]]]); }
function change($text,$incremental=null){ global $uri,$ver; $ver++; $cc = $incremental ?? [['text'=>$text]]; send(['jsonrpc'=>'2.0','method'=>'textDocument/didChange','params'=>['textDocument'=>['uri'=>$uri,'version'=>$ver],'contentChanges'=>$cc]]); }
function save($text){ global $uri; send(['jsonrpc'=>'2.0','method'=>'textDocument/didSave','params'=>['textDocument'=>['uri'=>$uri],'text'=>$text]]); }
send(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['processId'=>getmypid(),'rootUri'=>'file://'.$root,'capabilities'=>new stdClass]]);
$init=rd(12); foreach($init as $m){ if(($m['id']??0)===1){ echo 'textDocumentSync: ',json_encode($m['result']['capabilities']['textDocumentSync']??null),"\n"; } }
send(['jsonrpc'=>'2.0','method'=>'initialized','params'=>new stdClass]);
open($text); echo "open                         : ",diags(rd(6),$uri),"\n";
$lines=explode("\n",$text);
$fixed = str_replace("        return \$a;\n","        return true;\n",$text);          // replace the line
$removed = str_replace("        return \$a;\n","",$text);                              // delete the line entirely
$step=function(string $label,string $t,$inc=null){ change($t,$inc); echo str_pad($label,29),": ",diags(rd(5),$GLOBALS['uri']),"\n"; };
if($scenario==='replace'){ $step('replace line with true',$fixed); $step('restore broken',$text); $step('replace again',$fixed); }
if($scenario==='delete'){ $step('delete the line',$removed); $step('restore broken',$text); $step('delete again',$removed); }
if($scenario==='incremental'){
  // delete line 6 (0-based) incrementally
  $step('incremental delete line',$removed,[['range'=>['start'=>['line'=>6,'character'=>0],'end'=>['line'=>7,'character'=>0]],'text'=>'']]);
  $step('incremental re-insert',$text,[['range'=>['start'=>['line'=>6,'character'=>0],'end'=>['line'=>6,'character'=>0]],'text'=>"        return \$a;\n"]]);
  $step('incremental delete again',$removed,[['range'=>['start'=>['line'=>6,'character'=>0],'end'=>['line'=>7,'character'=>0]],'text'=>'']]);
}
if($scenario==='typing'){
  // type a fix character by character into a(): replace `$a` by `true`
  $cur=$text; foreach(['return $a;'=>'return $;','return $;'=>'return ;','return ;'=>'return t;','return t;'=>'return tr;','return tr;'=>'return tru;','return tru;'=>'return true;'] as $from=>$to){ $cur=str_replace($from,$to,$cur); $step('type -> '.$to,$cur); }
}
if($scenario==='save'){ $step('replace line with true',$fixed); file_put_contents($file,$fixed); save($fixed); echo "save                         : ",diags(rd(5),$uri),"\n"; $step('break again',$text); file_put_contents($file,$text); save($text); echo "save                         : ",diags(rd(5),$uri),"\n"; }
echo "stderr: ",substr(stream_get_contents($pipes[2]),0,300),"\n"; proc_terminate($p);
