# School Connect: an app for Gibbon™

This is the server module for **School Connect**, a mobile app for iPhone and Android. School Connect is a third-party application that is compatible with [Gibbon](https://gibbonedu.org), the free and open-source school management platform. It is not an official Gibbon Foundation release, and it is not affiliated with or endorsed by the Gibbon Foundation.

The app gives staff, students and parents quick access to the things they check most often: timetables, attendance, homework and due dates, grades, the planner, messages, reports and more. This module adds the secure API the app talks to. Install it on your school's Gibbon server, then sign in from the app with your school's web address.

## How it works

- The app connects **only** to your school's own Gibbon server, over HTTPS. Your school's data never passes through High Point Education's systems.
- Sign-in uses your existing Gibbon accounts and permissions, including Google Sign-In if your school uses it.
- Once a day, the module asks High Point's licence server whether your school's licence is active, sending **only your server's web address**. No personal data is sent.

Full details are in the [School Connect privacy policy](https://highpointedu.com/school-connect/privacy/privacy-policy.html). A GDPR data processing agreement is available on request.

## Requirements

- Gibbon v26.0.00 or later
- PHP with the cURL extension (or `allow_url_fopen` enabled)
- HTTPS on your Gibbon site

## Installation

1. Download the latest `School-Connect-x.y.zz.zip` from [Releases](../../releases).
2. Unzip it into your Gibbon `modules` folder, so the files sit in `modules/School Connect/`. The folder name must be exactly `School Connect`.
3. In Gibbon, go to **System Admin → Manage Modules**, find School Connect and click **Install**.
4. Visit `https://your-gibbon-site/index.php?q=/modules/School%20Connect/index.php` as an administrator. That page confirms the API files are present and shows the address to enter in the app. (It is deliberately hidden from Gibbon's main menu.)

## Updating

Replace the files in `modules/School Connect/` with the new release, then go to **System Admin → Manage Modules** and click **Update** next to School Connect.

## Licence

The module code is released under the GNU General Public License v3.0 (see [LICENSE](LICENSE)).

Using School Connect at a school requires an active School Connect subscription. New schools can start a free trial. Contact [tieku@highpointedu.com](mailto:tieku@highpointedu.com).

## Getting the app

During the pilot, School Connect is available on the App Store and Google Play by invitation. Contact [tieku@highpointedu.com](mailto:tieku@highpointedu.com) for access.

---

Developed by [High Point Education](https://highpointedu.com).
Gibbon™ is a trademark of Gibbon Education Ltd. (Hong Kong), used with permission.
