<?php
	class Tunnel {
		
		/**
		 *	Execute killcommand saved in DB to kill tunnel
		 */
		function killTunnel($forwardID) {		
			$statusrow = query2Array("select * from status where localForwardID='{$forwardID}'");
			
			if(sizeof($statusrow) == 0) {
				return true;
			}
			else 
			{			
				$status = $statusrow[0];
				
				if(isRunning($status['PID'])) {
						$descriptorspec = [
						   0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
						   1 => array("pipe", "r"),  // stdout is a pipe that the child will write to
						   2 => array("pipe", "r") // stderr is a file to write to
						];
				
					$proc = proc_open(stripslashes($status['killcommand']), $descriptorspec, $pipes);		
					sleep(1); //it may take some time to annhilate the PID
					
					sql("delete from status where localForwardID='{$forwardID}'");
					
					if(isRunning($status['PID'])) {
						errorMsg("Cannot kill tunnel", "Killcommand did not work, tunnel might still be operational. Killcommand used was: <br><pre>{$status['killcommand']}</pre>");
						die("");
						return false;
					} else {
						return true;
					}
				} else {
					sql("delete from status where localForwardID='{$forwardID}'");
				}		
				
				return true;
			}
		}
		
		/**
		 *	Use Apache as reverse proxy to expose Forward externally by generating & activating a virtualhost directive
		 */
		function createApacheVHost($cname, $fwdHost, $fwdPort) {
			$url = "{$cname}." . getConfigValue("Virtual host domain");
			$port = getConfigValue("Virtual host port");
			
			$fwd = "{$fwdHost}:{$fwdPort}";
			$vhost = "
						<VirtualHost {$url}:{$port}>
								ProxyPreserveHost On
								ProxyPass / http://{$fwd}/
								ProxyPassReverse / http://{$fwd}/

								RewriteEngine On
								RewriteCond %{HTTP:Upgrade} =websocket [NC]
								RewriteRule /(.*)           ws://{$fwd}/$1 [P,L]
								RewriteCond %{HTTP:Upgrade} !=websocket [NC]
								RewriteRule /(.*)           http://{$fwd}/$1 [P,L]

								ErrorLog \${APACHE_LOG_DIR}/{$url}.error.log
								CustomLog \${APACHE_LOG_DIR}/{$url}.access.log combined
						</VirtualHost>			
					";
			$dir = getConfigValue("Path to Apache2 configs") . DIRECTORY_SEPARATOR;
						
			$target = $dir . "trmanager_{$cname}.conf";
			
			$fp = fopen($target, "wa+");
			fwrite($fp, $vhost);
			fclose($fp);
			
			$out = shell_exec("sudo " . getConfigValue("Path to Apache2 a2ensite") . " trmanager_{$cname} 2>&1");
			
			if(!strstr($out, "Enabling site") && !strstr($out, "already enabled")) {
				errorMsg("VirtualHost creation failed", "Couldnt create virtual host. Error message: '{$out}'");
				die();
			} else {
				registerEvent("Virtualhost", "New virtualhost created for {$cname} that masks {$fwdHost}:{$fwdPort}");
			}
						
			$this->apacheReload();			
		}
		
		function removeApacheVHost($cname) {
			$vhost = "trmanager_{$cname}";
			$cmd= "sudo " . getConfigValue("Path to Apache2 a2dissite") . " {$vhost} 2>&1";
			$out = shell_exec($cmd);
			
			if($out != "" && !strstr($out, "removing dangling symlink") && !strstr($out, "does not exist")) {
				errorMsg("Could not disable Apache2 virtual host", "Command used was: '{$cmd}'.<br>Output:<br>'{$out}'</pre>");
				die();
			} else {
				registerEvent("Virtualhost", "Virtualhost removed for {$cname}");
			}
			
			$dir = getConfigValue("Path to Apache2 configs") . DIRECTORY_SEPARATOR;						
			$target = $dir . "trmanager_{$cname}.conf";
			
			unlink($target);
			
			$this->apacheReload();	
		}
		
		function apacheReload() {			
			$out = shell_exec("sudo " . getConfigValue("Path to Apache2 service") . " reload 2>&1");
			
			if($out != "") {
				errorMsg("Could not run Apache2 reload command", "Check if Visudo command specified works for www-data user.<br>Command used was: 'sudo /usr/sbin/service apache2 reload 2>&1'.<br>Output:<br>'{$out}'</pre>");
				die();
			} else {
				registerEvent("Virtualhost", "Reloaded Apache");
			}				
		}
		
		function startTunnel($forwardID) {				
			$location_corkscrew = getConfigValue("Path to Corkscrew");
			$location_autossh = getConfigValue("Path to AutoSSH");
			$location_sshadd = getConfigValue("Path to ssh-add");
			$location_tmp = getConfigValue("PID directory");
			
			$forward = tunnelInfo($forwardID);
			
			//local or reverse
			$type = $forward['type'] == 0 ? "L" : "R";
			
			//open command pipes
			$descriptorspec = [
			   0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
			   1 => array("pipe", "r"),  // stdout is a pipe that the child will write to
			   2 => array("pipe", "w") // stderr is a file to write to
			];
			
			/**			
			 *	Key management
			 */
			
			//write SSH key to temporary file so agent can load it
			$dir = $location_tmp;
			$keyfile = $dir . DIRECTORY_SEPARATOR . ".trmanager_c_" . $forward['connectionID'];
									
			$c = new Cipher();
			$keyContent = $c->decrypt($forward['SSHKey']);
			
			$fp = fopen($keyfile, "w");
			fwrite($fp, $keyContent);
			fclose($fp);		
						
			//ensure keyfile is NOT world readable	
			chmod($keyfile, 0600);
						
			//ensure (only one) agent is started
			$proc = proc_open(getcwd() . "/bin/start_agent.sh", $descriptorspec, $pipes);			
			sleep(1);
			
			//set environment variables to pinpoint agent location to the shell
			$agentContext = file_get_contents($location_tmp . DIRECTORY_SEPARATOR . ".ssh-agent");

			$agentEnv = explode(";", $agentContext);
			$vars = array();
			foreach($agentEnv as $v) {
					if(strstr($v, "=")) {
							putenv($v);
					}
			}
			
			//add key
            $out = shell_exec("ssh-add {$keyfile}");															
			
			//remove keyfile
			unlink($keyfile);
			
			/**			
			 *	Open tunnel
			 */
			
			$compression = ($forward['Compression'] == "yes") ? "-C" : "";
			$keepalive = ($forward['TCPKeepAlive'] == "yes") ? "TCPKeepAlive=yes" : "";
			$serveralive = ($forward['ServerAliveInterval'] > 0) ? "ServerAliveInterval=" . $forward['ServerAliveInterval'] : "";	
			
			//proxy configuration (suffix)
			$proxyhost = getConfigValue("Proxy Host");
			$proxyport  = getConfigValue("Proxy Port");
			$proxycommand = " -o ProxyCommand='{$location_corkscrew} {$proxyhost} {$proxyport} %h %p' ";
			
			$proxyEnabled = $proxyhost != "" && $proxyport != "";
			
			//recreate autoSSH command
			$command = "{$location_autossh} {$forward['SSHUser']}@{$forward['SSHHostName']} -p {$forward['SSHPort']} " . ($proxyEnabled ? $proxycommand : "") . " -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -g -{$type}{$forward['localPort']}:{$forward['remoteTargetHost']}:{$forward['remoteTargetPort']} -N {$compression} {$keepalive} {$serveralive} &";					
			
			//save kill command for later, just the forwarding string should do here.
			$killcommand = "pkill -f \"{$type}{$forward['localPort']}:{$forward['remoteTargetHost']}:{$forward['remoteTargetPort']}\"";						
			
			$proc = proc_open($command, $descriptorspec, $pipes);
			$proc_details = proc_get_status($proc);
			$pid = $proc_details['pid']+1;	//+1 because SSH instantenously recreates session
			
			//wait until tunnel is created (potentially)		
			$waitTime = getConfigValue("Tunnel open wait time (sec)");			
			sleep($waitTime); 		
			
			//read error from stream
			stream_set_blocking($pipes[2], 0);
			$response = stream_get_contents($pipes[2]);
			fclose($pipes[2]);												
					
			//process potential 'err'
			$errorStr = "";
			if(is_string($response)) {
				$errorlines = array();
				$lines = explode("\n", $response);
				foreach($lines as $k=>$v) {
					if(!strstr($v, "Could not create directory") && !strstr($v, "to the list of known hosts.")) {
						//real error 
						$errorlines[] .= $v;
					}
				}
				$errorStr = implode("<br>", $errorlines);
			}											
			
			if(trim($errorStr) != "") {
				errorMsg("Error occured - forward failed", $errorStr);	
				
				$proc = proc_open($killcommand, $descriptorspec, $pipes);					
				
				die();
			} else {
				registerEvent("Tunnel", "New tunnel created to enable Forward. Command used was: <br>'{$command}'");
				
				//register status
				$command = addslashes($command);
				$killcommand = addslashes($killcommand);
				
				sql("INSERT INTO status (connectionID,localForwardID,PID,errortext,command,killcommand) VALUES ('{$forward['connectionID']}', '{$forward['forwardID']}', '{$pid}', '{$errorStr}', '{$command}', '{$killcommand}')");								
				
				if($type == "L") {
					if($forward['virtualHost'] == 1) {												
						//add virtual host
						$this->createApacheVHost($forward['description'], "localhost", $forward['localPort']);
					} else {
						//remove virtual host
						$this->removeApacheVHost($forward['description']);
					}
				} 
			}									

		}
	
	}

	class Cipher
	{
		private $securekey;
		private $iv_size;

		function __construct()
		{
			global $CIPHER_KEY;
			$textkey = base64_encode($CIPHER_KEY);
			
			$this->iv_size = mcrypt_get_iv_size(
				MCRYPT_RIJNDAEL_128,
				MCRYPT_MODE_CBC
			);
			$this->securekey = hash(
				'sha256',
				$textkey,
				TRUE
			);
		}

		function encrypt($input)
		{
			$iv = mcrypt_create_iv($this->iv_size);
			return base64_encode(
				$iv . mcrypt_encrypt(
					MCRYPT_RIJNDAEL_128,
					$this->securekey,
					$input,
					MCRYPT_MODE_CBC,
					$iv
				)
			);
		}

		function decrypt($input)
		{
			$input = base64_decode($input);
			$iv = substr(
				$input,
				0,
				$this->iv_size
			);
			$cipher = substr(
				$input,
				$this->iv_size
			);
			return trim(
				mcrypt_decrypt(
					MCRYPT_RIJNDAEL_128,
					$this->securekey,
					$cipher,
					MCRYPT_MODE_CBC,
					$iv
				)
			);
		}
	}

?>