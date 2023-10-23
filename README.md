# Cloudflare DDNS

This is a small Dynamic DNS (DDNS) script for syncing your public (external) IP address with your Cloudflare DNS.

This was built to be used with my Synology DS918+ to update my DNS record for my Cloudflare zone when my non-static IP address changes at home as I have local services that I like to easily access remotely with a custom hostname.

## Installing

* Download and setup on your machine.

* Install composer dependencies:

    `$ composer install`

* [Create an API token at Cloudflare](https://dash.cloudflare.com/profile/api-tokens) or use your global key (security risk!):

    The token should have the following permissions (scopes):
    
    * Zones -> DNS  -> Edit
    * Zones -> Zone -> Read

* Copy and edit the example env config file:

    `$ cp .env.example .env`

## Setup

### Option 1: Synology Module

If you have a Synology server, you can make Cloudflare one of the DDNS service providers and it works just like any other built in one.

* Setup a symlink to the script with executable permissions:

```bash
$ ln -s /path/to/cloudflare-ddns/script.php /usr/syno/bin/ddns/cloudflare.php
$ chmod +x cloudflare.php
```

* Add the module to the DDNS provider config:

```bash
$ cat >> /etc.defaults/ddns_provider.conf << EOF
[Cloudflare]
        modulepath=/usr/syno/bin/ddns/cloudflare.php
        queryurl=https://www.cloudflare.com/
EOF
```

* Next, go to the Synology DiskStation and add the new `Cloudflare` service provider.

    * Control Panel -> External Access -> DDNS -> Add

## Option 2: Scheduled Task (cron)

* Setup a scheduled task (cron job) as frequently as you want to be checked (daily is common)

    `echo '0 * * * * root ' >> /etc/cron.d/crontab`

## Requirements

* Cloudflare account with your email and API key
* PHP 8.1 with Composer
* Domain name pointed at Cloudflare with the DNS zone activated
