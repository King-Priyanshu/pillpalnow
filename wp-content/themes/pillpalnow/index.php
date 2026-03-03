<?php
/**
 * The main template file
 *
 * @package PillPalNow
 */

// Determine App URL (Dashboard if logged in, Login if not)
// Using Ultimate Member default '/login' slug as requested ("um forms only")
$app_url = is_user_logged_in() ? home_url('/dashboard') : home_url('/login');
// For the iframe, we might want to show a demo or the real login/dashboard
$preview_url = home_url('/dashboard');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DoseApp - Your Personal Health Assistant</title>

    <?php wp_head(); ?>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <style>
        :root {
            --primary: #2563EB;
            --primary-dark: #1d4ed8;
            --secondary: #10B981;
            --accent: #F59E0B;
            --dark-bg: #0F172A;
            --darker-bg: #020617;
            --card-bg: #1E293B;
            --text: #F8FAFC;
            --text-muted: #94A3B8;
            --gradient-hero: linear-gradient(135deg, rgba(37, 99, 235, 0.2) 0%, rgba(16, 185, 129, 0.1) 100%);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--dark-bg);
            color: var(--text);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Utilities */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 1rem;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.5);
            background: var(--primary-dark);
        }

        .btn-outline {
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: var(--text);
            margin-left: 1rem;
        }

        .btn-outline:hover {
            border-color: var(--text);
            background: rgba(255, 255, 255, 0.05);
        }

        .text-gradient {
            background: linear-gradient(90deg, #60A5FA, #34D399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Navbar */
        .navbar {
            padding: 1.5rem 0;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            z-index: 50;
        }

        .nav-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-weight: 800;
            font-size: 1.5rem;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo span {
            color: var(--primary);
        }

        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            background: radial-gradient(circle at 50% 0%, #1e293b 0%, var(--dark-bg) 70%);
            padding-top: 4rem;
            position: relative;
        }

        .hero-content {
            text-align: center;
            max-width: 800px;
            margin: 0 auto;
            z-index: 10;
        }

        .hero h1 {
            font-size: 3.5rem;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            letter-spacing: -0.02em;
        }

        .hero p {
            font-size: 1.25rem;
            color: var(--text-muted);
            margin-bottom: 2.5rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-visual {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 800px;
            height: 800px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.1) 0%, rgba(0, 0, 0, 0) 70%);
            z-index: 1;
            pointer-events: none;
        }

        /* Features */
        .features {
            padding: 8rem 0;
            background: var(--darker-bg);
        }

        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-header h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .section-header p {
            color: var(--text-muted);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: transform 0.3s;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            border-color: rgba(37, 99, 235, 0.3);
        }

        .feature-icon {
            width: 60px;
            height: 60px;
            background: rgba(37, 99, 235, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            color: var(--primary);
        }

        /* Pricing */
        .pricing {
            padding: 8rem 0;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .pricing-cards {
            display: flex;
            justify-content: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .pricing-card {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: 24px;
            width: 100%;
            max-width: 350px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            position: relative;
        }

        .pricing-card.featured {
            border: 2px solid var(--primary);
            background: linear-gradient(180deg, rgba(37, 99, 235, 0.1) 0%, var(--card-bg) 100%);
        }

        .price {
            font-size: 3rem;
            font-weight: 700;
            margin: 1.5rem 0;
        }

        .price span {
            font-size: 1rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        .feature-list {
            list-style: none;
            margin-bottom: 2rem;
        }

        .feature-list li {
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
        }

        .check {
            color: var(--secondary);
        }

        /* Footer */
        footer {
            padding: 4rem 0;
            background: var(--darker-bg);
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            text-align: center;
            color: var(--text-muted);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5rem;
            }

            .hero p {
                font-size: 1.1rem;
            }

            .btn-outline {
                margin-left: 0;
                margin-top: 1rem;
                display: flex;
                width: 100%;
            }

            .pricing-cards {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>

<body <?php body_class(); ?>>

    <!-- Nav -->
    <nav class="navbar">
        <div class="container nav-content">
            <div class="logo">
                <span>⚡</span> DoseApp
            </div>
            <div>
                <a href="<?php echo esc_url($app_url); ?>" class="btn btn-primary"
                    style="padding: 0.5rem 1.2rem; font-size: 0.9rem;">
                    <?php echo is_user_logged_in() ? 'Dashboard' : 'Launch App'; ?>
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <header class="hero">
        <div class="hero-visual"></div>
        <div class="container hero-content">
            <h1>Your Personal <span class="text-gradient">Health Assistant</span></h1>
            <p>Never miss a dose again. Manage medications, track family adherence, and get smart reminders in one
                beautiful app.</p>
            <div style="display: flex; flex-wrap: wrap; justify-content: center;">
                <a href="<?php echo esc_url($app_url); ?>" class="btn btn-primary">
                    <?php echo is_user_logged_in() ? 'Go to Dashboard' : 'Get Started Free'; ?>
                </a>
                <a href="#features" class="btn btn-outline">Learn More</a>
            </div>

            <!-- Dashboard Preview Mockup -->
            <div style="margin-top: 4rem; position: relative; display: inline-block;">
                <div
                    style="width: 300px; height: 600px; background: var(--card-bg); border-radius: 40px; padding: 10px; border: 8px solid #334155; box-shadow: 0 20px 50px rgba(0,0,0,0.5); overflow: hidden; margin: 0 auto;">
                    <iframe src="<?php echo esc_url($preview_url); ?>"
                        style="width: 100%; height: 100%; border: none; background: var(--dark-bg); pointer-events:none;"></iframe>
                </div>
            </div>
        </div>
    </header>

    <!-- Features -->
    <section id="features" class="features">
        <div class="container">
            <div class="section-header">
                <h2>Why Choose DoseApp?</h2>
                <p>Designed for peace of mind and ease of use.</p>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">🔔</div>
                    <h3>Smart Reminders</h3>
                    <p style="color: var(--text-muted); margin-top: 0.5rem;">Intelligent notifications that adapt to
                        your schedule. Postpone or skip with a single tap.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">👨‍👩‍👧</div>
                    <h3>Family Tracking</h3>
                    <p style="color: var(--text-muted); margin-top: 0.5rem;">Care for your loved ones. Monitor adherence
                        for kids, parents, or pets in one place.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🛡️</div>
                    <h3>Secure & Private</h3>
                    <p style="color: var(--text-muted); margin-top: 0.5rem;">Your health data differs from ad data. We
                        prioritize local storage and encryption.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
 

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> DoseApp. All rights reserved.</p>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>

</html>