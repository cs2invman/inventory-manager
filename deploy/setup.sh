#!/bin/bash

# This script will setup the following:

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root"
    exit
fi

apt update
apt upgrade -y
apt install -y apt-transport-https ca-certificates curl software-properties-common git
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo apt-key add -
add-apt-repository "deb [arch=amd64] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable"
apt update
apt install -y docker-ce docker-ce-cli containerd.io
usermod -aG docker ubuntu
mkdir -p /home/ubuntu/.docker/cli-plugins/
curl -SL https://github.com/docker/compose/releases/download/v2.40.3/docker-compose-$(uname -s)-$(uname -m) -o /home/ubuntu/.docker/cli-plugins/docker-compose
chown -R ubuntu:ubuntu /home/ubuntu/.docker/
chmod +x /home/ubuntu/.docker/cli-plugins/docker-compose
echo "Highly recommended to reboot now: shutdown -r 0"
