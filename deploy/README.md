# Production Server Setup

## Setup Server

- Start with blank ubuntu server
- Copy `setup.sh` and run it as root to install docker
```
ubuntu # sudo -i
root # vim
root # vim setup.sh (If needed) - Use new docker install from their site
root # chmod +x setup.sh
root # ./setup.sh
```
- Create deploy key for this repo in gitlab. "Settings" > "Repository" > "Deploy Keys"
- When created add the key to: /home/ubuntu/deploy
```
ubuntu # ssh-keygen -t ed25519 -C "<comment>"
ubuntu # cat deploy.pub
```
- Pull Repo initially as the ubuntu user
```
ubuntu # cd ~
ubuntu # ssh-agent bash
ubuntu # ssh-add deploy
ubuntu # git clone git@git.northern.co:internal/enspire.git
ubuntu # mv deploy.pub enspire/deploy/deploy.pub
ubuntu # mv deploy enspire/deploy/deploy
```
