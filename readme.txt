=== VK Booking Manager ===
Contributors: vektor-inc,kurudrive
Tags: booking, reservations, appointment, salon, beauty
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress booking plugin for salons, beauty services, chiropractic, and private lessons with complex scheduling requirements.

== Description ==

**VK Booking Manager** is a comprehensive booking plugin for WordPress designed to handle complex scheduling scenarios for businesses like beauty salons, chiropractic clinics, and private lesson providers. It supports flexible shift patterns and can be used both as part of a website or as a standalone booking system.

## Key Features

### For Customers

* **Easy Online Booking** - Customers can book appointments through a user-friendly, responsive booking form that works on any device
* **Flexible Scheduling** - The system displays only available time slots based on complex business hours and service requirements
* **Account Management** - Customers can view their booking history and manage their appointments through their account
* **Booking Confirmation** - Automatic email notifications for booking confirmations and status updates

### For Business Owners

* **Complex Schedule Management** - Handle irregular business hours, multiple shifts per day, and special days (holidays, temporary closures)
* **Service Menu Management** - Create and organize service menus with pricing, duration, booking deadlines, and buffer times
* **Booking Management** - View and manage all bookings through the admin interface. The shift dashboard provides day and month views to see staff schedules and bookings at a glance
* **Tentative Booking Mode** - Review and confirm bookings before they are finalized, allowing for adjustments to time or pricing
* **Booking and Cancellation Deadlines** - Set deadlines for bookings and cancellations, with different settings per service menu
* **Customer Records** - Maintain customer records with booking history, treatment notes, and photos
* **Phone Booking Support** - Easily register bookings made over the phone through the booking block on the frontend. For existing registered customers, the system can automatically identify and assign the user based on their phone number for smooth operation

### Technical Features

* **Block Editor Support** - Use Gutenberg blocks to add booking forms and service menu displays to your pages
* **Simple Setup** - No need to create multiple pages; just add the booking block to a single page
* **Standalone Mode** - Can be used as a standalone booking system with minimal WordPress configuration
* **Role-Based Permissions** - Custom role for salon owners with booking-related functionality only. This allows smooth operation when using the plugin as a standalone booking system by hiding unnecessary menu items, and prevents accidental editing or deletion of non-booking content
* **Responsive Design** - Works seamlessly on desktop, tablet, and mobile devices

## Pro Version Features

The Pro version of VK Booking Manager includes additional features for businesses with multiple staff members:

* **Multiple Staff Management** - Manage unlimited staff members with individual schedules, nomination fees, and service assignments
* **Staff Nomination** - Customers can choose a specific staff member when booking. Nomination fees are set per staff member (the same fee applies to all services), but you can configure individual service menus to exclude nomination fees
* **Automatic Staff Assignment** - When customers don't select a staff member, the system automatically assigns an available staff member
* **Staff-Specific Schedules** - Set individual working hours and schedules for each staff member
* **Staff Assignment in Tentative Bookings** - Adjust staff assignments when reviewing and confirming tentative bookings

## Ideal For

* Beauty salons and hair salons
* Chiropractic and massage clinics
* Private lesson providers and tutoring services
* Any service business with complex scheduling needs

== Installation ==

= Automatic Installation =
1. Log in to your WordPress admin panel
2. Navigate to Plugins > Add New
3. Search for "VK Booking Manager"
4. Click "Install Now" and then "Activate"

= Manual Installation =
1. Download the plugin zip file
2. Extract the plugin folder
3. Upload the plugin folder to your `/wp-content/plugins/` directory using FTP or your hosting control panel
4. Activate the plugin through the WordPress Plugins screen

= Getting Started =
Create or edit a page and add the "Reservation" block to display the booking form. If any other required information is missing, alerts will be displayed in the dashboard. Please follow the instructions to complete the setup.

== Frequently Asked Questions ==

= What types of businesses can use this plugin? =

VK Booking Manager is designed for service businesses with complex scheduling needs, such as beauty salons, chiropractic clinics, private lesson providers, and any business that requires flexible scheduling.

= Can I use this plugin without a full WordPress website? =

Yes, you can use VK Booking Manager as a standalone booking system. Simply create a WordPress site and add the booking block to your homepage. The plugin includes special permissions that hide non-booking related menus for easier management.

= Does the plugin support multiple staff members? =

Multiple staff member support, including staff nomination and automatic staff assignment, is available in the Pro version of the plugin. In the Pro version, customers can select a specific staff member when booking. Nomination fees are set per staff member (the same fee applies to all services), but you can configure individual service menus to exclude nomination fees. If a customer doesn't select a staff member, the system will automatically assign an available staff member.

= What is tentative booking mode? =

When tentative booking mode is enabled, customer bookings are initially created as "pending" status. You can review the booking details, adjust the time or pricing if needed, and then confirm the booking. Once confirmed, the customer receives a confirmation email. In the Pro version, you can also adjust staff assignments when reviewing tentative bookings.

= Can I set different booking deadlines for different services? =

Yes, you can set booking deadlines both globally in the provider settings and individually for each service menu. This allows you to have different policies for different types of services.

= How do I handle phone bookings? =

You can register phone bookings through the booking block on your website's frontend. The booking form allows you to enter all booking details including customer information, service, date, and time. For existing registered customers, the system can automatically identify and assign the user based on their phone number, making the booking process smooth and efficient. In the Pro version, you can also assign staff members when registering phone bookings.

= Can customers view their booking history? =

Yes, logged-in customers can view their booking history and upcoming appointments through the "My Bookings" section accessible from the booking page navigation.

= How does the system handle complex schedules? =

The plugin supports irregular business hours, multiple shifts per day, special days (holidays, temporary closures), and service-specific availability. The booking form will only display time slots that are actually available based on all these factors. In the Pro version, you can also set staff-specific schedules for individual staff members.

== Screenshots ==

1. Booking form - User-friendly reservation interface with menu and staff selection
2. Service menu management - Create and organize service menus with pricing and duration
3. Shift dashboard - Day and month views showing staff schedules and bookings
4. Booking management - Admin interface for viewing and managing all bookings
5. Provider settings - Configure business hours, notifications, and policies

== Changelog ==

= 0.1.0 =
* Initial release.
