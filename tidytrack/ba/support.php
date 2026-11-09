<?php
session_start();

if (empty($_SESSION['ba_authenticated'])) {
    header('Location: index.php');
    exit;
}

if (
    empty($_SESSION['ba_user_id']) ||
    strcasecmp(
        trim($_SESSION['ba_department'] ?? ''),
        'BA Team'
    ) !== 0
) {
    header('Location: index.php');
    exit;
}

$baName = $_SESSION['ba_name'] ?? 'BA Team Member';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Help & Support | TidyTrack</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        :root {
            --primary: #0d9488;
            --primary-dark: #0f766e;
            --primary-light: #f0fdfa;
            --background: #f8fafc;
            --white: #ffffff;
            --text: #172b3a;
            --muted: #64748b;
            --border: #e2e8f0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--background);
            color: var(--text);
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }

        /* HEADER */

        .topbar {
            min-height: 78px;
            padding: 14px 6%;
            background: var(--white);
            border-bottom: 1px solid var(--border);

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 19px;
            font-weight: 800;
        }

        .brand-mark {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: grid;
            place-items: center;
            font-size: 21px;
            overflow: hidden;
        }

        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand small {
            display: block;
            margin-top: 3px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 500;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 11px 15px;
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            transition: 0.2s;
        }

        .back-button:hover {
            color: var(--primary-dark);
            border-color: var(--primary);
            background: var(--primary-light);
        }

        /* PAGE */

        .page {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            padding: 42px 24px 55px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
            color: var(--muted);
            font-size: 12px;
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .breadcrumb .current {
            color: var(--text);
            font-weight: 700;
        }

        /* INTRO */

        .intro {
            margin-bottom: 30px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            margin-bottom: 15px;

            border: 1px solid #ccfbf1;
            border-radius: 30px;

            background: var(--primary-light);
            color: var(--primary-dark);

            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .intro h1 {
            font-size: clamp(28px, 4vw, 38px);
            font-weight: 800;
            letter-spacing: -1px;
            margin-bottom: 12px;
        }

        .intro p {
            max-width: 650px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.8;
        }

        /* HELP BANNER */

        .help-banner {
            display: flex;
            align-items: center;
            gap: 20px;

            padding: 23px;
            margin-bottom: 25px;

            background: var(--primary-light);
            border: 1px solid #ccfbf1;
            border-radius: 16px;
        }

        .banner-icon {
            width: 55px;
            height: 55px;
            flex-shrink: 0;

            display: grid;
            place-items: center;

            background: var(--white);
            border-radius: 15px;
            color: var(--primary);
            font-size: 24px;
        }

        .help-banner h2 {
            font-size: 15px;
            margin-bottom: 6px;
        }

        .help-banner p {
            color: #475569;
            font-size: 12px;
            line-height: 1.8;
        }

        /* FORM CARD */

        .form-card {
            padding: 30px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, 0.025);
        }

        .card-heading {
            display: flex;
            align-items: center;
            gap: 13px;
            padding-bottom: 23px;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border);
        }

        .heading-icon {
            width: 45px;
            height: 45px;
            flex-shrink: 0;

            display: grid;
            place-items: center;

            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 18px;
        }

        .card-heading h2 {
            font-size: 17px;
            margin-bottom: 5px;
        }

        .card-heading p {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px;
        }

        .form-group {
            min-width: 0;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 9px;
            font-size: 12px;
            font-weight: 700;
        }

        .required {
            color: #dc2626;
        }

        .form-control {
            width: 100%;
            min-height: 46px;
            padding: 12px 14px;

            border: 1px solid var(--border);
            border-radius: 10px;

            background: var(--white);
            color: var(--text);
            font-size: 12px;

            outline: none;
            transition: 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.08);
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        textarea.form-control {
            min-height: 155px;
            resize: vertical;
            line-height: 1.7;
        }

        .field-help {
            display: block;
            margin-top: 8px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.7;
        }

        .character-count {
            text-align: right;
            color: var(--muted);
            font-size: 10px;
            margin-top: 7px;
        }

        /* PRIORITY */

        .priority-options {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .priority-option {
            position: relative;
        }

        .priority-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .priority-option label {
            min-height: 65px;
            margin: 0;
            padding: 12px;

            display: flex;
            align-items: center;
            gap: 10px;

            border: 1px solid var(--border);
            border-radius: 10px;

            cursor: pointer;
            transition: 0.2s;
        }

        .priority-option label:hover {
            border-color: #99f6e4;
        }

        .priority-option input:checked + label {
            background: var(--primary-light);
            border-color: var(--primary);
            box-shadow: 0 0 0 1px var(--primary);
        }

        .priority-option input:focus-visible + label {
            outline: 2px solid var(--primary);
            outline-offset: 3px;
        }

        .priority-dot {
            width: 10px;
            height: 10px;
            flex-shrink: 0;
            border-radius: 50%;
        }

        .priority-dot.low {
            background: #16a34a;
        }

        .priority-dot.medium {
            background: #ea580c;
        }

        .priority-dot.high {
            background: #dc2626;
        }

        .priority-text strong {
            display: block;
            font-size: 12px;
            margin-bottom: 4px;
        }

        .priority-text small {
            display: block;
            color: var(--muted);
            font-size: 10px;
            font-weight: 400;
        }

        /* FORM ACTIONS */

        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;

            padding-top: 25px;
            margin-top: 28px;

            border-top: 1px solid var(--border);
        }

        .privacy-note {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            max-width: 350px;

            color: var(--muted);
            font-size: 11px;
            line-height: 1.7;
        }

        .privacy-note i {
            margin-top: 3px;
            color: var(--primary);
        }

        .submit-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;

            min-height: 46px;
            padding: 0 20px;

            border: none;
            border-radius: 10px;

            background: var(--primary);
            color: var(--white);

            font-family: inherit;
            font-size: 12px;
            font-weight: 800;

            cursor: pointer;
            transition: 0.2s;
        }

        .submit-button:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* FRONTEND DEMO MESSAGE */

        .demo-message {
            display: none;
            padding: 15px 17px;
            margin-top: 20px;

            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;

            color: #92400e;
            font-size: 12px;
            line-height: 1.8;
        }

        .demo-message.show {
            display: block;
        }

        .demo-message i {
            margin-right: 7px;
        }

        /* FOOTER */

        .footer {
            padding: 25px 15px;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        /* MOBILE */

        @media (max-width: 650px) {
            .topbar {
                padding: 13px 5%;
                min-height: 70px;
            }

            .brand {
                gap: 9px;
                font-size: 16px;
            }

            .brand-mark {
                width: 38px;
                height: 38px;
            }

            .brand small {
                font-size: 10px;
            }

            .back-button {
                padding: 10px;
                font-size: 11px;
            }

            .back-button span {
                display: none;
            }

            .page {
                padding: 28px 5% 40px;
            }

            .intro {
                margin-bottom: 24px;
            }

            .help-banner {
                align-items: flex-start;
                gap: 13px;
                padding: 17px;
            }

            .banner-icon {
                width: 43px;
                height: 43px;
                font-size: 19px;
            }

            .form-card {
                padding: 21px 17px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 19px;
            }

            .form-group.full {
                grid-column: auto;
            }

            .priority-options {
                grid-template-columns: 1fr;
            }

            .priority-option label {
                min-height: 58px;
            }

            .form-footer {
                flex-direction: column;
                align-items: stretch;
            }

            .submit-button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<header class="topbar">

    <a href="dashboard.php" class="brand">
        <div class="brand-mark">
            <img
                src="assets/images/logo.png"
                alt="TidyTrack"
                onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=&quot;fa-solid fa-layer-group&quot;></i>';"
            >
        </div>

        <div>
            TidyTrack
            <small>Business Analyst Portal</small>
        </div>
    </a>

    <a href="dashboard.php" class="back-button">
        <i class="fa-solid fa-arrow-left"></i>
        <span>Back to dashboard</span>
    </a>

</header>

<main class="page">

    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <i class="fa-solid fa-chevron-right"></i>
        <span class="current">Help & Support</span>
    </nav>

    <section class="intro">

        <span class="eyebrow">
            <i class="fa-regular fa-circle-question"></i>
            Help Centre
        </span>

        <h1>How can we help you?</h1>

        <p>
            Having trouble with TidyTrack? Tell us what's happening.
            Use this form to describe a system problem, ask for assistance,
            or report something that isn't working as expected.
        </p>

    </section>

    <section class="help-banner">

        <div class="banner-icon">
            <i class="fa-solid fa-headset"></i>
        </div>

        <div>
            <h2>We're here to help</h2>

            <p>
                Please describe the problem clearly and include the page
                where it happened. This will help the support team
                understand the issue.
            </p>
        </div>

    </section>

    <section class="form-card">

        <div class="card-heading">

            <div class="heading-icon">
                <i class="fa-solid fa-message"></i>
            </div>

            <div>
                <h2>Report an issue</h2>
                <p>Complete the form below to describe your issue.</p>
            </div>

        </div>

        <form id="supportForm">

            <div class="form-grid">

                <div class="form-group">
                    <label for="userName">Your name</label>

                    <input
                        type="text"
                        id="userName"
                        class="form-control"
                        value="<?= htmlspecialchars($baName, ENT_QUOTES, 'UTF-8') ?>"
                        readonly
                    >
                </div>

                <div class="form-group">
                    <label for="issueCategory">
                        Issue category <span class="required">*</span>
                    </label>

                    <select
                        id="issueCategory"
                        class="form-control"
                        required
                    >
                        <option value="">Select a category</option>
                        <option value="Login and account">Login and account</option>
                        <option value="Projects">Projects</option>
                        <option value="Tasks">Tasks and assignments</option>
                        <option value="Dashboard">Dashboard and reports</option>
                        <option value="Profile">Profile and settings</option>
                        <option value="Technical error">Technical error</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label for="issueTitle">
                        Issue title <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="issueTitle"
                        class="form-control"
                        placeholder="Briefly describe the problem"
                        maxlength="120"
                        required
                    >

                    <small class="field-help">
                        Example: I cannot open my assigned project.
                    </small>
                </div>

                <div class="form-group full">
                    <label for="issueDescription">
                        Describe your issue <span class="required">*</span>
                    </label>

                    <textarea
                        id="issueDescription"
                        class="form-control"
                        placeholder="Explain what happened, what you expected to happen, and any error message you saw..."
                        maxlength="2000"
                        required
                    ></textarea>

                    <div class="character-count">
                        <span id="characterCount">0</span>/2000 characters
                    </div>
                </div>

                <div class="form-group full">
                    <label>
                        How urgent is this issue?
                        <span class="required">*</span>
                    </label>

                    <div class="priority-options">

                        <div class="priority-option">
                            <input
                                type="radio"
                                name="priority"
                                id="priorityLow"
                                value="Low"
                                required
                            >

                            <label for="priorityLow">
                                <span class="priority-dot low"></span>

                                <span class="priority-text">
                                    <strong>Low</strong>
                                    <small>General question</small>
                                </span>
                            </label>
                        </div>

                        <div class="priority-option">
                            <input
                                type="radio"
                                name="priority"
                                id="priorityMedium"
                                value="Medium"
                                required
                            >

                            <label for="priorityMedium">
                                <span class="priority-dot medium"></span>

                                <span class="priority-text">
                                    <strong>Medium</strong>
                                    <small>Work is affected</small>
                                </span>
                            </label>
                        </div>

                        <div class="priority-option">
                            <input
                                type="radio"
                                name="priority"
                                id="priorityHigh"
                                value="High"
                                required
                            >

                            <label for="priorityHigh">
                                <span class="priority-dot high"></span>

                                <span class="priority-text">
                                    <strong>High</strong>
                                    <small>Cannot continue working</small>
                                </span>
                            </label>
                        </div>

                    </div>
                </div>

            </div>

            <div class="demo-message" id="demoMessage" role="status">
                <i class="fa-solid fa-circle-info"></i>
                <strong>Frontend preview:</strong>
                 This issue has not been saved
                or sent to a support team. Database submission will be
                added in a later step
            </div>

            <div class="form-footer">

                <p class="privacy-note">
                    <i class="fa-solid fa-shield-halved"></i>

                    <span>
                        Please do not include passwords or other sensitive
                        account information in your report.
                    </span>
                </p>

                <button type="submit" class="submit-button">
                    <i class="fa-solid fa-paper-plane"></i>
                    Submit issue
                </button>

            </div>

        </form>

    </section>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal
    &copy; <?= date('Y') ?>. All rights reserved.
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const description = document.getElementById('issueDescription');
    const characterCount = document.getElementById('characterCount');
    const supportForm = document.getElementById('supportForm');
    const demoMessage = document.getElementById('demoMessage');

    description.addEventListener('input', function () {
        characterCount.textContent = this.value.length;
    });

    supportForm.addEventListener('submit', function (event) {
        event.preventDefault();

        if (!supportForm.checkValidity()) {
            supportForm.reportValidity();
            return;
        }

        demoMessage.classList.add('show');

        demoMessage.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    });

});
</script>

</body>
</html>