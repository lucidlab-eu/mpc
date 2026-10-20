<?php
declare(strict_types=1);
/**
 * Standalone regression test: php tests/admin-polling.php (needs Node.js, like run-regressions.ps1)
 *
 * Only one plugin tab drives a job; other tabs read its status every 15 s (60 s when hidden). A job
 * that waits after HTTP 429 (retry_at) is only read until it resumes. A hidden leader hands over.
 */
$js    = (string) file_get_contents( __DIR__ . '/../assets/js/admin.js' );
$start = strpos( $js, "\tvar mpcHttpPause" );
$stop  = strpos( $js, "\tfunction mpcText(" );
$delay = strpos( $js, "\tfunction mpcPollDelay(" );
$end   = false === $delay ? false : strpos( $js, "\t/* Exponential backoff", $delay );
if ( false === $start || false === $stop || false === $delay || false === $end ) {
	fwrite( STDERR, "FAIL: polling functions not found in admin.js\n" );
	exit( 1 );
}
$node = trim( (string) shell_exec( 'node --version 2>&1' ) );
if ( ! str_starts_with( $node, 'v' ) ) {
	echo "Admin polling: skipped, Node.js is not available.\n";
	exit( 0 );
}
$harness = <<<'JS'
var store={};var window={localStorage:{getItem:function(k){return k in store?store[k]:null;},setItem:function(k,v){store[k]=v;},removeItem:function(k){delete store[k];}},addEventListener:function(){}};
var document={hidden:false};var setInterval=function(){};
JS;
$checks = <<<'JS'
var now=Date.now()/1000,bad=[];function eq(n,a,b){if(a!==b)bad.push(n+': '+a+' !== '+b);}
eq('leader',mpcIsLeader(),true);
eq('leader polls a browser-driven job quickly',mpcPollDelay({status:'running',driver:'ajax'}),400);
eq('leader steps',cronPollAction({status:'running',driver:'ajax',updated:now}),'step');
eq('waiting job is only read',cronPollAction({status:'running',driver:'ajax',retry_at:now+100}),'status');
eq('waiting job is read at most every minute',mpcPollDelay({status:'running',driver:'ajax',retry_at:now+100}),60000);
store.lucidlab_mpc_poll_leader=JSON.stringify({id:'other',t:Date.now()});
eq('second tab is not the leader',mpcIsLeader(),false);
eq('second tab reads every 15 s',mpcPollDelay({status:'running',driver:'ajax'}),15000);
eq('second tab does not step',cronPollAction({status:'running',driver:'ajax',updated:now}),'status');
eq('second tab steps a job nobody drives',cronPollAction({status:'running',driver:'ajax',updated:now-60}),'step');
document.hidden=true;eq('hidden second tab reads every minute',mpcPollDelay({status:'running',driver:'cron'}),60000);
document.hidden=false;store.lucidlab_mpc_poll_leader=JSON.stringify({id:'other',t:Date.now()-7000});
eq('visible tab takes over from a silent leader',mpcIsLeader(),true);
document.hidden=true;store.lucidlab_mpc_poll_leader=JSON.stringify({id:'other',t:Date.now()-7000});
eq('hidden tab does not take over',mpcIsLeader(),false);
console.log(bad.length?'FAIL '+bad.join('; '):'OK');
JS;
$file = tempnam( sys_get_temp_dir(), 'mpcpoll' ) . '.js';
file_put_contents( $file, $harness . "\n" . substr( $js, $start, $stop - $start ) . "\n" . substr( $js, $delay, $end - $delay ) . "\n" . $checks );
$out = trim( (string) shell_exec( 'node ' . escapeshellarg( $file ) . ' 2>&1' ) );
unlink( $file );
if ( 'OK' !== $out ) {
	fwrite( STDERR, 'FAIL: ' . $out . "\n" );
	exit( 1 );
}
echo "Admin polling: one tab drives jobs, other and hidden tabs poll slowly, jobs waiting after HTTP 429 are only read, a hidden leader hands over.\n";
