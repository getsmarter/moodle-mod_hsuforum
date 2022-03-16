<?php

namespace mod_hsuforum\task;

class delete_expired_custom_drafts extends \core\task\scheduled_task
{

    /**
     * @inheritDoc
     */
    public function get_name()
    {
        return get_string('deleteexpiredcustomdraftstaskname', 'mod_hsuforum');
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        global $CFG;
        require_once($CFG->dirroot . '/mod/hsuforum/lib.php');
        delete_expired_custom_drafts();
    }
}
