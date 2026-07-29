<?php

return [

    'app' => [
        'tagline' => 'The federated, simple, no-masters social network.',
    ],

    'nav' => [
        'home' => 'Home',
        'world' => 'World',
        'profile' => 'Profile',
        'notifications' => 'Notifications',
        'search' => 'Search',
        'settings' => 'Settings',
        'login' => 'Log in',
        'register' => 'Sign up',
        'logout' => 'Log out',
    ],

    'home' => [
        'hero_title' => 'Welcome to :app',
        'hero_subtitle' => 'A general-purpose social network, federated with the Fediverse: talk to anyone, wherever they are.',
        'cta_register' => 'Create an account',
        'cta_login' => 'I already have an account',
        'instance_about_title' => 'About this instance',
    ],

    'auth' => [
        'register_title' => 'Create your account',
        'login_title' => 'Log in to :app',
        'username' => 'Username',
        'username_help' => 'Lowercase letters, numbers and underscores only. It will appear as :handle',
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'login_identifier' => 'Username or email',
        'remember_me' => 'Remember me',
        'submit_register' => 'Sign up',
        'submit_login' => 'Log in',
        'already_registered' => 'Already have an account?',
        'not_registered' => "Don't have an account yet?",
    ],

    'verify_email' => [
        'title' => 'Verify your email address',
        'body' => 'Before continuing, please check your inbox: we sent you a verification link.',
        'resend' => "Didn't receive it? Resend the email",
        'sent' => 'A new verification email has been sent.',
    ],

    'profile' => [
        'followers' => 'Followers',
        'following' => 'Following',
        'communities' => 'Communities',
        'joined_on' => 'Joined on :date',
        'protected' => 'Protected account',
        'pinned_posts' => 'Pinned posts',
        'no_posts_yet' => 'There are no posts to show yet.',
    ],

    'feed' => [
        'welcome_title' => 'You are signed in, :name',
        'welcome_body' => 'The feed with posts from people and communities you follow will land in the next development phase of Openbook. For now you can visit your public profile.',
        'view_profile' => 'Go to your profile',
        'empty' => 'There are no posts to show yet. Follow other people or publish your first post.',
    ],

    'composer' => [
        'body_label' => 'What do you want to share?',
        'placeholder' => 'Write something...',
        'title_label' => 'Title (optional)',
        'cw_label' => 'Content warning (optional)',
        'cw_placeholder' => 'E.g. spoiler, sensitive topic...',
        'images_label' => 'Images',
        'images_help' => 'Up to :count images per post (JPEG, PNG, WebP or GIF).',
        'alt_label' => 'Alt text for the first image',
        'alt_help' => 'Describe the image content for people using a screen reader.',
        'visibility_label' => 'Visibility',
        'submit' => 'Post',
    ],

    'visibility' => [
        'public' => 'Public',
        'unlisted' => 'Unlisted',
        'followers' => 'Followers only',
        'direct' => 'Direct (mentioned people only)',
    ],

    'posts' => [
        'page_title' => 'Posts by :name',
        'edited' => 'edited',
        'deleted' => 'This post has been deleted.',
        'content_warning_label' => 'Content warning',
        'confirm_delete' => 'Are you sure you want to delete this post?',
    ],

    'actions' => [
        'like' => 'Like (:count)',
        'liked' => 'Liked (:count)',
        'comment' => 'Comment (:count)',
        'comment_submit' => 'Post comment',
        'announce' => 'Share (:count)',
        'announced' => 'Shared (:count)',
        'shared_this' => 'shared this post',
        'reply' => 'Reply',
        'delete' => 'Delete',
    ],

    'comments' => [
        'title' => 'Comments (:count)',
        'new_label' => 'Write a comment',
        'empty' => 'No comments yet. Be the first to comment.',
        'deleted' => 'This comment has been deleted.',
        'confirm_delete' => 'Are you sure you want to delete this comment?',
        'reply_to' => 'Reply to :name',
        'login_to_comment' => 'Log in to leave a comment.',
    ],

    'follow' => [
        'follow' => 'Follow',
        'unfollow' => 'Unfollow',
        'cancel_request' => 'Cancel request',
        'pending_requests' => 'Pending follow requests',
        'accept' => 'Accept',
        'reject' => 'Reject',
        'requested' => 'Request sent',
    ],

    'search' => [
        'title' => 'Search the Fediverse',
        'placeholder' => 'user@domain or profile URL',
        'help' => 'Enter the federated address of a person (e.g. person@otherinstance.social) to find them, view their profile and follow them, even if they are not on this instance.',
        'submit' => 'Search',
    ],

    'actors' => [
        'remote_notice' => 'Remote profile: the data shown here comes from the origin server and may not be up to date in real time.',
    ],

    'settings' => [
        'title' => 'Settings',
        'edit_profile' => 'Edit profile',
        'save' => 'Save',
        'profile_updated' => 'Profile updated.',
        'account_updated' => 'Preferences updated.',
        'profile_section_title' => 'Public profile',
        'avatar_label' => 'Profile picture',
        'image_preview_help' => "You'll see a preview as soon as you choose a file, even before saving.",
        'cover_label' => 'Cover image',
        'display_name_label' => 'Display name',
        'bio_label' => 'Bio',
        'links_label' => 'Links',
        'link_label_placeholder' => 'Label (e.g. Website)',
        'links_help' => 'Up to 4 links shown on your public profile.',
        'account_section_title' => 'Account & privacy',
        'locale_label' => 'Interface language',
        'default_visibility_label' => 'Default visibility for new posts',
        'protected_account_label' => 'Protected account',
        'protected_account_help' => 'When enabled, new follow requests stay pending until you approve them manually.',
        'discoverable_label' => 'Include my account in suggestions and search results',
        'discoverable_help' => 'When disabled, you will not appear under "People to follow" or in this instance\'s search results (you remain reachable through your direct address).',
    ],

    'follows' => [
        'followers_title' => 'Followers of :name',
        'following_title' => ':name follows',
        'back_to_profile' => 'Back to profile',
        'empty_followers' => 'No followers yet.',
        'empty_following' => 'Not following anyone yet.',
    ],

    'notifications' => [
        'title' => 'Notifications',
        'empty' => 'You have no notifications yet.',
        'view' => 'View',
        'someone' => 'Someone',
        'messages' => [
            'new_follower' => ':name started following you.',
            'follow_request' => ':name requested to follow you.',
            'follow_accepted' => ':name accepted your follow request.',
            'like' => ':name liked your content.',
            'comment' => ':name commented on your post.',
            'reply' => ':name replied to your comment.',
            'mention' => ':name mentioned you.',
            'share' => ':name shared your post.',
        ],
    ],

    'hashtags' => [
        'empty' => 'No public posts with this hashtag yet.',
    ],

    'sidebar' => [
        'instance_title' => 'This instance',
        'members_count' => '{0} No members yet|{1} :count member|[2,*] :count members',
        'people_to_follow' => 'People to follow',
    ],

    'world' => [
        'title' => 'World',
        'subtitle' => 'Public posts that reached this instance from other servers in the fediverse: only what is already relevant here (accounts you follow, replies, mentions), not a full index of the fediverse.',
        'suggested_title' => 'Discover on the fediverse',
        'empty' => 'No posts from the rest of the fediverse yet. Follow someone on another instance to start seeing their content here.',
    ],

    'footer' => [
        'license' => 'free software licensed under AGPL-3.0-or-later',
    ],

];
