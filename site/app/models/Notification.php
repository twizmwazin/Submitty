<?php

namespace app\models;

use app\libraries\Core;

/**
 * Class Notification
 *
 * @method void     setViewOnly($view_only)
 * @method void     setId($id)
 * @method void     setComponent($component)
 * @method void     setSeen($isSeen)
 * @method void     setElapsedTime($duration)
 * @method void     setCreatedAt($time)
 * @method void     setNotifyMetadata()
 * @method void     setNotifyContent()
 *
 * @method bool     isViewOnly()
 * @method int      getId()
 * @method string   getComponent()
 * @method bool     isSeen()
 * @method real     getElapsedTime()
 * @method string   getCreatedAt()
 * @method string   getCurrentUser()
 *
 * @method string   getNotifySource()
 * @method string   getNotifyTarget()
 * @method string   getNotifyContent()
 * @method string   getNotifyMetadata()
 * @method bool     getNotifyNotToSource()
 */
class Notification extends AbstractModel {
    /** @property @var bool Notification fetched from DB */
    protected $view_only;

    /** @property @var string Type of component */
    protected $component;
    /** @property @var string Current logged in user */
    protected $current_user;

    /** @property @var string Notification source user (can be null) */
    protected $notify_source;
    /** @property @var string Notification target user(s) (null implies all users) */
    protected $notify_target;
    /** @property @var string Notification text content */
    protected $notify_content;
    /** @property @var string Notification information about redirection link */
    protected $notify_metadata;
    /** @property @var bool Should $notify_source be ignored from $notify_target */
    protected $notify_not_to_source;

    /** @property @var int Notification ID */
    protected $id;
    /** @property @var bool Is notification already seen */
    protected $seen;
    /** @property @var real Time elapsed from creation of notification in secs */
    protected $elapsed_time;
    /** @property @var string Timestamp for creation of notification */
    protected $created_at;


    /**
     * Notifications constructor.
     *
     * @param Core  $core
     * @param array $details
     */
    public function __construct(Core $core, $details=array()) {
        parent::__construct($core);
        if (count($details) == 0) {
            return;
        }
        if(!empty($details['view_only'])){
            $this->setViewOnly(true);
            $this->setId($details['id']);
            $this->setSeen($details['seen']);
            $this->setComponent($details['component']);
            $this->setElapsedTime($details['elapsed_time']);
            $this->setCreatedAt($details['created_at']);
            $this->setNotifyMetadata($details['metadata']);
            $this->setNotifyContent($details['content']);
        } else {
            $this->setViewOnly(false);
            $this->setNotifyNotToSource(true);
            $this->setCurrentUser($this->core->getUser()->getId());
            $this->setComponent($details['component']);
            switch ($this->getComponent()) {
                case 'forum':
                    $this->handleForum($details);
                    break;
                default:
                    // Prevent notification to be pushed in database
                    $this->setComponent("invalid");
                    break;
            }
        }
    }

    /**
     * Returns the corresponding url based on metadata
     *
     * @param  Core     core
     * @param  string   metadata
     * @return string   url
     */
    public static function getUrl($core, $metadata_json) {
        $metadata = json_decode($metadata_json, true);
        if(empty($metadata)) {
            return null;
        }
        $parts = $metadata[0];
        $hash = $metadata[1] ?? null;
        return $core->buildUrl($parts, $hash);
    }

    /**
     * @return Does notification has active url
     */
    public function hasUrl() {
        $url = Notification::getUrl($this->core, $this->getNotifyMetadata());
        return !empty($url);
    }

    /**
     * Handles notifications related to forum
     *
     * @param array $details
     */
    private function handleForum($details) {
        switch ($details['type']) {
            case 'new_announcement':
                $this->actAsNewAnnouncementNotification($details['thread_id'], $details['thread_title']);
                break;
            case 'updated_announcement':
                $this->actAsUpdatedAnnouncementNotification($details['thread_id'], $details['thread_title']);
                break;
            case 'reply':
                $this->actAsForumReplyNotification($details['thread_id'], $details['post_id'], $details['post_content'], $details['reply_to']);
                break;
            case 'merge_thread':
                $this->actAsForumMergeThreadNotification($details['parent_thread_id'],  $details['parent_thread_title'], $details['child_thread_title'], $details['child_root_post'], $details['reply_to']);
                break;
            case 'edited':
                $this->actAsForumEditedNotification($details['thread_id'], $details['post_id'], $details['post_content'], $details['reply_to']);
                break;
            case 'deleted':
                $this->actAsForumDeletedNotification($details['thread_id'],  $details['post_content'], $details['reply_to']);
                break;
            case 'undeleted':
                $this->actAsForumUndeletedNotification($details['thread_id'], $details['post_id'], $details['post_content'], $details['reply_to']);
                break;
            default:
                return;
        }
    }

    public static function metadataForum($thread_id, $post_id = -1) {
        $data = null;
        if($post_id == -1) {
            $data = array(array('component' => 'forum', 'page' => 'view_thread', 'thread_id' => $thread_id));
        } else {
            $data = array(array('component' => 'forum', 'page' => 'view_thread', 'thread_id' => $thread_id), (string)$post_id);
        }
        return json_encode($data);
    }

    public static function metadataNone() {
        $data = array();
        return json_encode($data);
    }

    private function actAsNewAnnouncementNotification($thread_id, $thread_title) {
        $this->setNotifyMetadata($this->metadataForum($thread_id));
        $this->setNotifyContent("New Announcement: ".$thread_title);
        $this->setNotifySource($this->getCurrentUser());
        $this->setNotifyTarget(null);
    }

    private function actAsUpdatedAnnouncementNotification($thread_id, $thread_title) {
        $this->setNotifyMetadata($this->metadataForum($thread_id));
        $this->setNotifyContent("Announcement: ".$thread_title);
        $this->setNotifySource($this->getCurrentUser());
        $this->setNotifyTarget(null);
    }

    private function actAsForumReplyNotification($thread_id, $post_id, $post_content, $target) {
        $this->setNotifyMetadata($this->metadataForum($thread_id, $post_id));
        $this->setNotifyContent("Reply: Your post '".$this->textShortner($post_content)."' got new a reply from ".$this->getCurrentUser());
        $this->setNotifySource($this->getCurrentUser());
        $this->setNotifyTarget($target);
    }

    private function actAsForumMergeThreadNotification($parent_thread_id, $parent_thread_title, $child_thread_title, $child_root_post, $target){
        $this->setNotifyMetadata($this->metadataForum($parent_thread_id, $child_root_post));
        $this->setNotifyContent("Thread Merged: '".$this->textShortner($child_thread_title)."' got merged into '".$this->textShortner($parent_thread_title)."'");
        $this->setNotifySource($this->getCurrentUser());
        $this->setNotifyTarget($target);
    }

    private function actAsForumEditedNotification($thread_id, $post_id, $post_content, $target){
        $this->setNotifyMetadata($this->metadataForum($thread_id, $post_id));
        $this->setNotifyContent("Update: Your thread/post '".$this->textShortner($post_content)."' got an edit from ".$this->getCurrentUser());
        $this->setNotifySource($this->getCurrentUser());
        $this->setNotifyTarget($target);
    }

    private function actAsForumDeletedNotification($thread_id, $post_content, $target){
        $this->setNotifyMetadata($this->metadataNone());
        $this->setNotifyContent("Deleted: Your thread/post '".$this->textShortner($post_content)."' was deleted by ".$this->getCurrentUser());
        $this->setNotifySource($this->getCurrentUser());
        $this->setNotifyTarget($target);
    }

    private function actAsForumUndeletedNotification($thread_id, $post_id, $post_content, $target){
        $this->setNotifyMetadata($this->metadataForum($thread_id, $post_id));
        $this->setNotifyContent("Undeleted: Your thread/post '".$this->textShortner($post_content)."' has been undeleted by ".$this->getCurrentUser());
        $this->setNotifySource($this->getCurrentUser());
        $this->setNotifyTarget($target);
    }

    /**
     * Trim long $message upto 40 character and filter newline
     *
     * @param string $message
     * @return $trimmed_message
     */
    private function textShortner($message) {
        $max_length = 40;
        $message = str_replace("\n", " ", $message);
        if(strlen($message) > $max_length) {
            $message = substr($message, 0, $max_length - 3) . "...";
        }
        return $message;
    }

    /**
     * Returns relative time if time is in last 24 hours
     * else returns absolute time
     *
     * @return string $formatted_time
     */
    public function getNotifyTime() {
        $elapsed_time = $this->getElapsedTime();
        $actual_time = $this->getCreatedAt();
        if($elapsed_time < 60){
            return "Less than a minute ago";
        } else if($elapsed_time < 3600){
            $minutes = floor($elapsed_time/60);
            if($minutes == 1)
                return "1 minute ago";
            else
                return "{$minutes} minutes ago";
        } else if($elapsed_time < 3600*24){
            $hours = floor($elapsed_time/3600);
            if($hours == 1)
                return "1 hour ago";
            else
                return "{$hours} hours ago";
        } else {
            return date_format(date_create($actual_time), "n/j g:i A");
        }
    }
}
