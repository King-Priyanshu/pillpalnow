=== PillPalNow Theme ===

1. Installation
   - Upload the `pillpalnow` folder to your `/wp-content/themes/` directory.
   - Activate the theme via Appearance > Themes.

2. Setup
   - Dashboard: Create a page named "Dashboard" (content can be empty) and set it as the Static Homepage in Settings > Reading. Or just let the theme handle the front page.
   - Profile: Create a page named "Profile" and assign the "Profile Page" template to it.
   - History: Create a page named "History" and assign the "History Page" template to it.
   - Medications: Go to "Medications" in the admin menu and add your medications (Title = Name, Content = Details, Custom Fields for dosage if needed, Featured Image).

3. Customization
   - Go to Appearance > Customize.
   - Use "Colors" to change the Primary, Background, and Card colors.
   - Use "Dashboard Settings" to change labels.
   - Use "Menus" to set up your "Primary Menu".

4. Developers
   - Styles are in `style.css`.
   - Core logic in `functions.php`.
   - Page templates: `page-profile.php`, `page-history.php`.
