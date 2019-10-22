# WooCommerce Coupon Gateway

## Description

This plugin is designed to prevent users from accessing a WooCommerce-anabled WordPress website unless 
they are admins or they have a valid Coupon code. 

## How to Use

To grant customers access to the site, they must be sent a link with a WooCommerce Coupon code as a query
string in the form for `example.com?wcg=couponcode`. 

Once they click that link and visit the site, the site will check that the Coupon Code is valid 
(ie: the code is a legit Coupon AND it has not reached its "usage limit"). A valid Coupon code 
will be stored as a cookie on the users browser and will allow the user to navigate the site. 

When the user checks out, the Coupon is marked as used behind the scenes (ie: user does not need to
enter the code anywhere during checkout). Once a Coupon code has reached its usage limit, any user with
that Coupon code stored as a cookie will only be able to view a Thank You page. 

The plugin assumes that there is a Thank You page located as `example.com/thank-you`, so make sure that page is created within WordPress. 

## How to Test

- Install / activate plugin.
- Create some valid Coupons in WooCommerce.
- Open an incognito window (which saves cookies only until you close the window) and try to visit the site without being logged in -- you should get an error and should not be able to navigate the site.
- Now try to visit the site with the query string at the end of the url, `?wcg={valid coupon code}` -- you should be able to navigate the site. 
- Try using a nonexistent Coupon code, `?wcg=badcode` -- you should get an error. 
- Use a valid code in the query string again and make a test purchase -- after the Order is complete, you should be directed to a thank you page and should no longer be able to navigate the site.
- Login as an admin and confirm that the Coupon has been applied to that Order. 

## How to Update to Latest version

1. In WordPress, deactivate and delete the current version of the plugin
- In GitHub, from the Code tab find and click the Releases link
- Download the source code (zip) for the latest release
- Optional: when you download the source code and unzip you'll see that the version number is appended to the folder name. This probably won't cause any issues, but you may want to rename the folder without the version number before installing. In that case you'd need to rename, then create a new zip of the renamed folder. The plus side of not renaming is that you should be able to keep the old version installed (but deactivated) while you're testing the new version.
- In WordPress, upload and activate the new plugin.
- Boom, you're done.
