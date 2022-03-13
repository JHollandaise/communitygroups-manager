The primary issue is that we are updating the WP posts based on a temporal state
The only difference we are currently evaluating is the additional of new posts
and the removal of old posts. We are not currently evaluating at the granularity
of post fields

ie, in order to prevent having to update the posts every time both
- a user loads *any page* on the site => incures a time penalty to UX (time-based)
- every time the page is loaded the posts are "updated" => incures a rapidly
    growing number of "post revisions" (storage)


The solution to both of these problems is to run the update in the background
with a wp-cron and to only update the posts when they actually change by first
comparing the state of the custom fields coming in from the CS API response with
the state of the custom fields in the corresponding WP post


The priority is the second issue, as eventually the post revisions cause the
site to lock up and crash