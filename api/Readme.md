# Minimal STACK API

The purpose of the files in this directory are to provide a minimal and direct API to STACK questions.

This is to prove the functionality of including STACK questions in other interactive websites.

## Installation

1. Install Maxima and Gnuplot on your server.  (Plots not yet supported).
1. Download 'qtype_stack' onto your webserver.  For example into the directory

    $CFG->wwwroot = "/var/www/api";

2. You must have a data directory into which the webserver can write.  Don't put this in your web directory.

    $CFG->dataroot = "/var/data/api";

3. Create the following temporary directories given in '$CFG->dataroot'.  [TODO: automate this?]

    $CFG->dataroot.'/stack'
    $CFG->dataroot.'/stack/plots'
    $CFG->dataroot.'/stack/logs'
    $CFG->dataroot.'/stack/tmp'

4. Copy 'api/config.php.dist' to '$CFG->wwwroot. "/config.php"' and edit the file to reflect your current settings.
5. Edit 'minimal.php' to run the command '$api->install();'.  This command compiles a Maxima image with your local settings. You will now need to edit 'config.php' to point to your maxima image.  This varies by lisp version, but it might look like this:
   
    $CFG->maximacommand = 'timeout --kill-after=10s 10s /usr/lib/clisp-2.49/base/lisp.run -q -M /var/data/api/stack/maxima_opt_auto.mem';

6. Comment out the install command in 'minimal.php'.  This should now run a basic question.


Note, at this stage there is no error trapping....