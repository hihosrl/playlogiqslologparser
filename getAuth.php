<?php
checkAuth();
//aws sts get-session-token --serial-number arn:aws:iam::087158905062:mfa/p.tozzi@playlogiq.com --token-code 921343 --duration-seconds 129600
function checkAuth(){
$output = shell_exec("aws logs tail /aws/rds/cluster/playlogiq-cluster/slowquery --profile mfa --since 1m" . " 2>&1");
if (preg_replace('/ExpiredTokenException/','',$output)!=$output || preg_replace('/Unable to locate credentials/','',$output)!=$output || preg_replace('/token included in the request is invalid/','',$output)!=$output){
	echo "access denied";
	do {
		sleep(1);
		echo "\ncontrollo mfacode";
		clearstatcache();
		$ts = filemtime('/var/www/html/2fa_code.inc');
		echo "\n".(time()-$ts);
		$diff=(time()-$ts);
	}while($diff>=5);
	//leggo mfa
	$new2fa = trim(file_get_contents('/var/www/html/2fa_code.inc'));
	if (strlen($new2fa)>4){
	echo "\nNuovo token MFA: " . $new2fa . "\n";    
    $mfa_code = $new2fa;
$output = shell_exec("aws sts get-session-token --serial-number arn:aws:iam::087158905062:mfa/p.tozzi@playlogiq.com --token-code ".$mfa_code." --duration-seconds 129600" . " 2>&1");
$js=json_decode($output,1);
if (is_array($js['Credentials'])){
	$AAKI=$js['Credentials']['AccessKeyId'];
	$ASAK=$js['Credentials']['SecretAccessKey'];
	$AST=$js['Credentials']['SessionToken'];
	$AEXP=$js['Credentials']['Expiration'];

	$credfile=implode('', file('./credentials_template'));
	$credfile=preg_replace('/#AAKI#/',$AAKI,$credfile);
	$credfile=preg_replace('/#ASAK#/',$ASAK,$credfile);
	$credfile=preg_replace('/#AST#/',$AST,$credfile);
	echo $credfile;
	$fp=fopen ('./credentials','w');
	fputs($fp,$credfile);
	fclose($fp);
	sleep(10);
	checkAuth();
}else{
	echo "\nerrore credenziali";
	sleep(30);
	checkAuth();
}
	
}else{
	echo "\nerrore 2fa riprovo in 10 sec";
	sleep(10);
	checkAuth();
}
}else{
	$output=passthru("php getLogAndParse" . " 2>&1");
	echo $output;
	echo "\n\nAspetto 1 ora prima del prossimo parsing";
	set_time_limit(3800);
	sleep (3600);
	checkAuth();
}


}

?>
