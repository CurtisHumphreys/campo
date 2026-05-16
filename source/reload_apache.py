#!/usr/bin/env python3
import paramiko, getpass, sys

pw = getpass.getpass("sudo password: ")
client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect('localhost', username='admin', key_filename='/home/admin/.ssh/id_ed25519')
stdin, stdout, stderr = client.exec_command('sudo -S systemctl reload apache2')
stdin.write(pw + '\n')
stdin.flush()
exit_code = stdout.channel.recv_exit_status()
print('stdout:', stdout.read().decode())
print('stderr:', stderr.read().decode())
print('exit:', exit_code)
client.close()
