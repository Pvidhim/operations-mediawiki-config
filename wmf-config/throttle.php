<?php
# WARNING: This file is publicly viewable on the web. Do not put private data here.

# Initialize the array. Append to that array to add a throttle
$wmgThrottlingExceptions = [];

# $wmgThrottlingExceptions is an array of arrays of parameters:
#  'from'  => date/time to start raising account creation throttle
#  'to'    => date/time to stop
#
# Optional arguments can be added to set the value or restrict by client IP
# or project dbname. Options are:
#  'value'  => new value for $wgAccountCreationThrottle (default: 50)
#  'IP'     => client IP as given by $wgRequest->getIP() or array (default: any IP)
#  'range'  => alternatively, the client IP CIDR ranges or array (default: any range)
#  'dbname' => a $wgDBname or array of dbnames to compare to
#             (eg. enwiki, metawiki, frwikibooks, eswikiversity)
#             Note that the limit is for the total number of account
#             creations on all projects. (default: any project)
# Example:
# $wmgThrottlingExceptions[] = [
# 'from'   => '2016-01-01T00:00 +0:00',
# 'to'     => '2016-02-01T00:00 +0:00',
# 'IP'     => '123.456.78.90',
# 'dbname' => [ 'xxwiki', etc. ],
# 'value'  => xx
# ];
## Add throttling definitions below.
#
## If you are adding a throttle exception with a 'from' time that is less than
## 72 hours in advance, you will also need to manually clear a cache after
## deploying your change to this file!
## https://wikitech.wikimedia.org/wiki/Increasing_account_creation_threshold

$wmgThrottlingExceptions[] = [ // T244645
	'from' => '2020-02-18T16:00 GMT+1',
	'to' => '2020-02-18T18:00 GMT+1',
	'IP' => '85.192.74.190',
	'dbname' => 'cawiki',
	'value' => 20 // expected 10
];

$wmgThrottlingExceptions[] = [ // T244608
	'from' => '2020-02-28T14:00 PST',
	'to' => '2020-02-28T17:00 PST',
	'range' => '128.193.8.0/24',
	'dbname' => 'enwiki',
	'value' => 50
];

$wmgThrottlingExceptions[] = [ // T244608
	'from' => '2020-03-13T14:00 PDT',
	'to' => '2020-03-13T17:00 PDT',
	'range' => '128.193.8.0/24',
	'dbname' => 'enwiki',
	'value' => 50
];

$wmgThrottlingExceptions[] = [ // T244488
	'from' => '2020-03-05T13:00 -5:00',
	'to' => '2020-03-05T20:00 -5:00',
	'IP' => '24.137.221.250',
	'dbname' => [ 'enwiki', 'frwiki' ],
	'value' => 50
];

## Add throttling definitions above.
