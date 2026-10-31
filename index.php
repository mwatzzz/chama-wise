<?php

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'contact') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('index.php#contact');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    if ($name === '' || $email === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Please complete the contact form with a valid email.');
        redirect('index.php#contact');
    }

    flash_set('success', 'Thank you. Your message has been received.');
    redirect('index.php#contact');
}

$pageTitle = 'ChamaWise | Smarter Chamas. Stronger Futures.';
$navType = 'public';
$bodyClass = 'public-body';
require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="container hero-layout">
        <div class="hero-copy">
            <p class="eyebrow">Stronger together</p>
            <h1>Smarter Chamas.<br><span>Stronger Futures.</span></h1>
            <p>Manage your chama contributions, loans, repayments and financial records in one simple platform with personalised AI-powered financial guidance for every member.</p>
            <div class="hero-actions">
                <a class="btn btn-brand" href="<?php echo e(url('auth/register.php')); ?>">Create Your Account</a>
                <a class="btn btn-outline-brand" href="<?php echo e(url('auth/login.php')); ?>">Login</a>
            </div>
        </div>
        <div class="hero-visual">
            <img
                class="hero-photo"
                src="<?php echo e(asset('images/chama-community.jpg')); ?>"
                alt="African colleagues sharing ideas as they work together"
                fetchpriority="high"
            >
            <div class="hero-photo-caption">
                <span class="hero-photo-icon" aria-hidden="true">&#10003;</span>
                <span><strong>Growing together</strong><small>One community. Brighter futures.</small></span>
            </div>
        </div>
    </div>
</section>

<section class="section" id="about">
    <div class="container">
        <p class="eyebrow text-center">Why ChamaWise</p>
        <h2 class="text-center display-heading mb-3">Everything Your Chama Needs in One Place</h2>
        <p class="text-center text-muted mx-auto mb-5" style="max-width: 36rem;">Bring transparency, structure and smart guidance to how your savings group manages money together.</p>
        <div class="row g-4">
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#128196;</div>
                    <h3>Transparent Records</h3>
                    <p class="text-muted mb-0">Keep clear and organised contribution and financial records.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#10024;</div>
                    <h3>AI Financial Advisor</h3>
                    <p class="text-muted mb-0">Get personalised financial guidance based on your chama account information.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#128101;</div>
                    <h3>Simple Loan Management</h3>
                    <p class="text-muted mb-0">Apply, review and track chama loans and repayments.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#128736;</div>
                    <h3>Automated Penalties</h3>
                    <p class="text-muted mb-0">Automatically calculate applicable contribution penalties.</p>
                </article>
            </div>
        </div>
    </div>
</section>

<section class="section section-mint" id="features">
    <div class="container">
        <p class="eyebrow text-center">Features</p>
        <h2 class="text-center mb-3">Powerful Tools for Better Chama Management</h2>
        <p class="text-center text-muted mx-auto mb-5" style="max-width: 36rem;">Every core module your group needs to run confidently from members to money to meetings.</p>
        <div class="row g-4">
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#128100;</div>
                    <h3>Member Management</h3>
                    <p class="text-muted mb-0">Manage member profiles and account information.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#128176;</div>
                    <h3>Contribution Tracking</h3>
                    <p class="text-muted mb-0">Record and view contribution history.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#128221;</div>
                    <h3>Meeting Minutes</h3>
                    <p class="text-muted mb-0">Keep organised records of chama meetings and minutes.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#10024;</div>
                    <h3>AI Financial Advisor</h3>
                    <p class="text-muted mb-0">Ask financial questions and receive personalised guidance.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#128101;</div>
                    <h3>Loan Management</h3>
                    <p class="text-muted mb-0">Apply for, approve and track chama loans.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="feature-card">
                    <div class="icon-circle">&#128202;</div>
                    <h3>Treasury &amp; Reporting</h3>
                    <p class="text-muted mb-0">View financial summaries and generate reports.</p>
                </article>
            </div>
        </div>
    </div>
</section>

<section class="section" id="how-it-works">
    <div class="container">
        <p class="eyebrow text-center">How It Works</p>
        <h2 class="text-center mb-3">Get Started in Four Simple Steps</h2>
        <p class="text-center text-muted mx-auto mb-5" style="max-width: 36rem;">From joining your chama to understanding your finances ChamaWise keeps it simple.</p>
        <div class="row g-4">
            <div class="col-md-6">
                <article class="step-card">
                    <div class="d-flex justify-content-between">
                        <span class="step-number">01</span>
                        <span class="icon-circle mb-0">&#128101;</span>
                    </div>
                    <h3 class="mt-3">Create Your Account</h3>
                    <p class="text-muted mb-0">Register as a chama member.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="step-card">
                    <div class="d-flex justify-content-between">
                        <span class="step-number">02</span>
                        <span class="icon-circle mb-0">&#128179;</span>
                    </div>
                    <h3 class="mt-3">Track Your Finances</h3>
                    <p class="text-muted mb-0">View contributions, loans, repayments and penalties.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="step-card">
                    <div class="d-flex justify-content-between">
                        <span class="step-number">03</span>
                        <span class="icon-circle mb-0">&#128221;</span>
                    </div>
                    <h3 class="mt-3">Stay Connected</h3>
                    <p class="text-muted mb-0">Access meetings, records and financial updates.</p>
                </article>
            </div>
            <div class="col-md-6">
                <article class="step-card">
                    <div class="d-flex justify-content-between">
                        <span class="step-number">04</span>
                        <span class="icon-circle mb-0">&#10024;</span>
                    </div>
                    <h3 class="mt-3">Get Personalised Guidance</h3>
                    <p class="text-muted mb-0">Use the AI Financial Advisor to understand your financial position.</p>
                </article>
            </div>
        </div>
    </div>
</section>

<section class="ai-band">
    <div class="container">
        <span class="pill" style="background: rgba(255,255,255,0.12); color: #fff;">AI Financial Advisor</span>
        <h2 class="text-white mt-4">Financial Guidance That Understands Your Chama</h2>
        <p>The AI Financial Advisor uses relevant account information to provide personalised and context-aware financial guidance.</p>
    </div>
</section>

<section class="section" id="contact">
    <div class="container">
        <div class="cta-card mb-5">
            <h2>Ready to Manage Your Chama Smarter?</h2>
            <p class="text-muted">Bring your chama's financial records together in one simple platform.</p>
            <div class="d-flex flex-column align-items-center gap-3 mt-4">
                <a class="btn btn-brand" href="<?php echo e(url('auth/register.php')); ?>">Create Your Account</a>
                <a class="btn btn-outline-brand" href="<?php echo e(url('auth/login.php')); ?>">Login</a>
            </div>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="contact-card">
                    <h2>Contact</h2>
                    <p class="text-muted">Questions about ChamaWise? Send a message and we will get back to you.</p>
                    <form method="post" action="<?php echo e(url('index.php#contact')); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form" value="contact">
                        <div class="mb-3">
                            <label class="form-label" for="contact_name">Name</label>
                            <input class="form-control" id="contact_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="contact_email">Email</label>
                            <input class="form-control" id="contact_email" name="email" type="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="contact_message">Message</label>
                            <textarea class="form-control" id="contact_message" name="message" rows="4" required></textarea>
                        </div>
                        <button class="btn btn-brand" type="submit">Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
