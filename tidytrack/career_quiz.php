<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI-Powered Career Assessment</title>
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --card-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--primary-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="20" cy="20" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="40" r="0.5" fill="rgba(255,255,255,0.08)"/><circle cx="40" cy="80" r="1.5" fill="rgba(255,255,255,0.06)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            animation: float 20s ease-in-out infinite;
            z-index: 0;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(1deg); }
        }

        .quiz-container {
            width: 100%;
            max-width: 700px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 1;
        }

        .quiz-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .quiz-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite alternate;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(180deg); }
        }

        .quiz-header h2 {
            font-weight: 700;
            font-size: 28px;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .quiz-subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 400;
            position: relative;
            z-index: 2;
        }

        .progress-container {
            margin-top: 20px;
            position: relative;
            z-index: 2;
        }

        .progress-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #fff, #f0f8ff);
            transition: var(--transition);
            border-radius: 10px;
            position: relative;
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
        }

        .quiz-body {
            padding: 50px;
            min-height: 400px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
        }

        .question-counter {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .question {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 40px;
            color: #1a202c;
            line-height: 1.4;
            position: relative;
        }

        .question::before {
            content: '"';
            font-size: 60px;
            color: #e2e8f0;
            position: absolute;
            top: -20px;
            left: -30px;
            font-family: Georgia, serif;
        }

        .options {
            display: grid;
            gap: 16px;
            margin-bottom: 40px;
        }

        .option {
            padding: 20px 24px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 16px;
            font-weight: 500;
            text-align: left;
            position: relative;
            overflow: hidden;
        }

        .option::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.6), transparent);
            transition: left 0.5s;
        }

        .option:hover::before {
            left: 100%;
        }

        .option:hover {
            background: #eef2ff;
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.15);
        }

        .option.selected {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.25);
        }

        .option.selected::after {
            content: '✓';
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: bold;
            font-size: 18px;
        }

        .nav-buttons {
            display: flex;
            justify-content: space-between;
            gap: 16px;
        }

        .btn {
            padding: 16px 32px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s, height 0.3s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-prev {
            background: linear-gradient(135deg, #e2e8f0, #cbd5e0);
            color: #4a5568;
        }

        .btn-next, .btn-submit {
            background: var(--primary-gradient);
            color: white;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .results {
            text-align: center;
            padding: 50px;
            animation: fadeInUp 0.6s ease-out;
        }

        .results-icon {
            font-size: 64px;
            margin-bottom: 20px;
            background: var(--success-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .results h3 {
            font-size: 32px;
            margin-bottom: 16px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        .results-description {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 30px;
            font-size: 18px;
        }

        .score-breakdown {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .score-item {
            padding: 20px;
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            border-radius: 16px;
            border-left: 4px solid #667eea;
        }

        .score-label {
            font-size: 14px;
            color: #718096;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .score-value {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
        }

        .career-tags {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            margin: 30px 0;
        }

        .career-tag {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            color: #4c51bf;
            border: 2px solid #c3dafe;
            padding: 12px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
        }

        .career-tag:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.2);
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 30px;
        }

        .btn-restart {
            background: linear-gradient(135deg, #718096, #4a5568);
            color: white;
        }

        .btn-dashboard {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
        }

        .btn-download {
            background: var(--secondary-gradient);
            color: white;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .question-enter {
            animation: slideInRight 0.5s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @media (max-width: 768px) {
            .quiz-body {
                padding: 30px 25px;
            }
            .quiz-header {
                padding: 25px 20px;
            }
            .quiz-header h2 {
                font-size: 24px;
            }
            .question {
                font-size: 20px;
            }
            .btn {
                padding: 12px 24px;
                font-size: 14px;
            }
            .nav-buttons {
                flex-direction: column;
            }
            .score-breakdown {
                grid-template-columns: 1fr;
            }
        }

        .tooltip {
            position: relative;
            cursor: help;
        }

        .tooltip::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            background: #1a202c;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            z-index: 1000;
        }

        .tooltip:hover::after {
            opacity: 1;
            visibility: visible;
        }
    </style>
</head>
<body>
    <div class="quiz-container">
        <!-- Header -->
        <div class="quiz-header">
            <h2>🎯 AI-Powered Career Assessment</h2>
            <p class="quiz-subtitle">Discover your perfect career path with intelligent analysis</p>
            <div class="progress-container">
                <div class="progress-info">
                    <span id="progress-text">Question 1 of 10</span>
                    <span id="progress-percent">0%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress" id="progress-bar"></div>
                </div>
            </div>
        </div>

        <!-- Quiz Body -->
        <div class="quiz-body" id="quiz-body">
            <!-- Questions will be loaded here dynamically -->
        </div>
    </div>

    <script>
        // Simulate user data (replace with actual PHP session data)
        const userData = {
            soft_skills: 'leadership communication teamwork',
            technical_skills: 'python javascript data analysis',
            qualification_name: 'Computer Science',
            position: 'Software Developer'
        };

        // Enhanced quiz questions with more sophisticated scoring
        const questions = [
            {
                text: "I thrive when solving complex technical problems and enjoy working with data, algorithms, or systems.",
                category: "technical_aptitude",
                weights: { tech: 4, mixed: 2, nonTech: 0, leadership: 1 }
            },
            {
                text: "I prefer collaborating with people, building relationships, and helping others achieve their goals.",
                category: "people_focus",
                weights: { tech: 0, mixed: 2, nonTech: 4, leadership: 3 }
            },
            {
                text: "I enjoy creating, designing, and bringing innovative ideas to life through various mediums.",
                category: "creativity",
                weights: { tech: 1, mixed: 4, nonTech: 2, leadership: 2 }
            },
            {
                text: "I excel at organizing projects, leading teams, and making strategic decisions.",
                category: "leadership",
                weights: { tech: 2, mixed: 4, nonTech: 3, leadership: 4 }
            },
            {
                text: "I am comfortable with programming, software tools, and learning new technologies quickly.",
                category: "technical_skills",
                weights: { tech: 4, mixed: 2, nonTech: 0, leadership: 1 }
            },
            {
                text: "I enjoy analyzing business problems and finding solutions that balance technical and human factors.",
                category: "analytical_thinking",
                weights: { tech: 3, mixed: 4, nonTech: 1, leadership: 3 }
            },
            {
                text: "I prefer working in dynamic environments where I can interact with diverse groups of people.",
                category: "social_interaction",
                weights: { tech: 1, mixed: 3, nonTech: 4, leadership: 3 }
            },
            {
                text: "I am detail-oriented and enjoy tasks that require precision and systematic thinking.",
                category: "attention_to_detail",
                weights: { tech: 3, mixed: 2, nonTech: 1, leadership: 2 }
            },
            {
                text: "I am motivated by teaching, mentoring, or helping others develop their skills.",
                category: "mentoring",
                weights: { tech: 1, mixed: 3, nonTech: 4, leadership: 4 }
            },
            {
                text: "I enjoy working with cutting-edge technology and staying updated with industry trends.",
                category: "innovation",
                weights: { tech: 4, mixed: 3, nonTech: 0, leadership: 2 }
            }
        ];

        // Enhanced career database with more detailed information
        const careerDatabase = {
            tech: {
                title: "Technology & Engineering Specialist",
                description: "You excel in technical problem-solving and working with complex systems. Your analytical mind thrives in structured, logic-driven environments.",
                careers: ["Software Engineer", "Data Scientist", "Cybersecurity Specialist", "AI/ML Engineer", "DevOps Engineer", "Systems Architect"],
                skills: ["Programming", "Problem Solving", "Analytical Thinking", "Technical Documentation", "System Design"],
                industries: ["Technology", "Finance", "Healthcare", "Gaming", "Aerospace"]
            },
            mixed: {
                title: "Strategic Hybrid Professional",
                description: "You have a balanced skill set perfect for roles that bridge technical and business domains. You can translate complex ideas into actionable strategies.",
                careers: ["Product Manager", "UX Designer", "Business Analyst", "Technical Consultant", "Solutions Architect", "Project Manager"],
                skills: ["Strategic Thinking", "Cross-functional Collaboration", "Problem Solving", "Communication", "Process Optimization"],
                industries: ["Consulting", "Technology", "Healthcare", "Finance", "E-commerce"]
            },
            nonTech: {
                title: "People & Creative Leader",
                description: "You thrive in human-centered roles where emotional intelligence and creativity drive success. You excel at building relationships and inspiring others.",
                careers: ["HR Manager", "Marketing Director", "Sales Manager", "Teacher", "Content Creator", "Event Coordinator"],
                skills: ["Leadership", "Communication", "Creativity", "Emotional Intelligence", "Relationship Building"],
                industries: ["Education", "Marketing", "Healthcare", "Non-profit", "Entertainment"]
            },
            leadership: {
                title: "Executive & Strategic Leader",
                description: "You have strong leadership qualities with the ability to make strategic decisions and guide organizations toward success.",
                careers: ["CEO/Executive", "Operations Manager", "Strategy Consultant", "Team Lead", "Department Head", "Entrepreneur"],
                skills: ["Strategic Planning", "Decision Making", "Team Management", "Vision Setting", "Change Management"],
                industries: ["Management", "Consulting", "Finance", "Technology", "Healthcare"]
            }
        };

        let currentQuestion = 0;
        let scores = { tech: 0, mixed: 0, nonTech: 0, leadership: 0 };
        let answers = [];
        let selectedOption = null;

        // DOM Elements
        const quizBody = document.getElementById('quiz-body');
        const progressBar = document.getElementById('progress-bar');
        const progressText = document.getElementById('progress-text');
        const progressPercent = document.getElementById('progress-percent');

        // Initialize quiz
        showQuestion();

        function showQuestion() {
            const q = questions[currentQuestion];
            
            const options = [
                { value: 4, label: "Strongly Agree", emoji: "💯" },
                { value: 3, label: "Agree", emoji: "👍" },
                { value: 2, label: "Neutral", emoji: "🤔" },
                { value: 1, label: "Disagree", emoji: "👎" },
                { value: 0, label: "Strongly Disagree", emoji: "❌" }
            ];

            let optionsHTML = '';
            options.forEach(opt => {
                const isSelected = selectedOption === opt.value ? 'selected' : '';
                optionsHTML += `
                    <div class="option ${isSelected}" data-value="${opt.value}">
                        <span>${opt.emoji} ${opt.label}</span>
                    </div>
                `;
            });

            quizBody.innerHTML = `
                <div class="question-counter">Question ${currentQuestion + 1} of ${questions.length}</div>
                <p class="question question-enter">${q.text}</p>
                <div class="options">${optionsHTML}</div>
                <div class="nav-buttons">
                    <button class="btn btn-prev" ${currentQuestion === 0 ? 'disabled' : ''}>
                        ← Previous
                    </button>
                    <button class="btn ${currentQuestion === questions.length - 1 ? 'btn-submit' : 'btn-next'}">
                        ${currentQuestion === questions.length - 1 ? 'Get Results ✨' : 'Next →'}
                    </button>
                </div>
            `;

            // Update progress
            const progress = ((currentQuestion + 1) / questions.length) * 100;
            progressBar.style.width = `${progress}%`;
            progressText.textContent = `Question ${currentQuestion + 1} of ${questions.length}`;
            progressPercent.textContent = `${Math.round(progress)}%`;

            // Attach event listeners
            document.querySelectorAll('.option').forEach(option => {
                option.addEventListener('click', () => {
                    document.querySelectorAll('.option').forEach(o => o.classList.remove('selected'));
                    option.classList.add('selected');
                    selectedOption = parseInt(option.getAttribute('data-value'));
                });
            });

            document.querySelector('.btn-prev')?.addEventListener('click', goPrev);
            document.querySelector('.btn-next, .btn-submit').addEventListener('click', 
                currentQuestion === questions.length - 1 ? submitQuiz : goNext
            );
        }

        function goNext() {
            if (selectedOption === null) {
                showNotification("Please select an option before proceeding.", "warning");
                return;
            }

            // Enhanced scoring system
            const q = questions[currentQuestion];
            answers.push({ question: currentQuestion, answer: selectedOption });

            // Apply weights based on answer strength
            const multiplier = selectedOption >= 3 ? 1 : selectedOption === 2 ? 0.5 : 0.2;
            
            Object.keys(q.weights).forEach(category => {
                scores[category] += q.weights[category] * multiplier;
            });

            currentQuestion++;
            selectedOption = null;
            
            if (currentQuestion < questions.length) {
                showQuestion();
            }
        }

        function goPrev() {
            if (currentQuestion > 0) {
                // Remove last answer and adjust scores
                const lastAnswer = answers.pop();
                const q = questions[lastAnswer.question];
                const multiplier = lastAnswer.answer >= 3 ? 1 : lastAnswer.answer === 2 ? 0.5 : 0.2;
                
                Object.keys(q.weights).forEach(category => {
                    scores[category] -= q.weights[category] * multiplier;
                });

                currentQuestion--;
                showQuestion();
            }
        }

        function submitQuiz() {
            if (selectedOption === null) {
                showNotification("Please select an option before submitting.", "warning");
                return;
            }

            // Process final answer
            const q = questions[currentQuestion];
            answers.push({ question: currentQuestion, answer: selectedOption });

            const multiplier = selectedOption >= 3 ? 1 : selectedOption === 2 ? 0.5 : 0.2;
            Object.keys(q.weights).forEach(category => {
                scores[category] += q.weights[category] * multiplier;
            });

            showLoadingScreen();
            setTimeout(showResults, 2000); // Simulate AI processing
        }

        function showLoadingScreen() {
            quizBody.innerHTML = `
                <div style="text-align: center;">
                    <div class="loading-spinner"></div>
                    <h3>🧠 Analyzing Your Responses</h3>
                    <p>Our AI is processing your answers and matching you with optimal career paths...</p>
                </div>
            `;
            progressBar.style.width = '100%';
            progressPercent.textContent = '100%';
        }

        function showResults() {
            // Determine primary career path
            const maxScore = Math.max(...Object.values(scores));
            const primaryPath = Object.keys(scores).find(key => scores[key] === maxScore);
            const careerData = careerDatabase[primaryPath];

            // Calculate percentages
            const total = Object.values(scores).reduce((a, b) => a + b, 0);
            const percentages = {};
            Object.keys(scores).forEach(key => {
                percentages[key] = Math.round((scores[key] / total) * 100);
            });

            // Personalize recommendations based on user data
            let personalizedCareers = [...careerData.careers];
            if (userData.technical_skills.includes('python')) {
                personalizedCareers.unshift('Python Developer');
            }
            if (userData.soft_skills.includes('leadership')) {
                personalizedCareers.unshift('Tech Lead');
            }

            quizBody.innerHTML = `
                <div class="results">
                    <div class="results-icon">🎯</div>
                    <h3>Your Career DNA: ${careerData.title}</h3>
                    <p class="results-description">${careerData.description}</p>
                    
                    <div class="score-breakdown">
                        <div class="score-item">
                            <div class="score-label">Technical</div>
                            <div class="score-value">${percentages.tech}%</div>
                        </div>
                        <div class="score-item">
                            <div class="score-label">Hybrid</div>
                            <div class="score-value">${percentages.mixed}%</div>
                        </div>
                        <div class="score-item">
                            <div class="score-label">People-Focused</div>
                            <div class="score-value">${percentages.nonTech}%</div>
                        </div>
                        <div class="score-item">
                            <div class="score-label">Leadership</div>
                            <div class="score-value">${percentages.leadership}%</div>
                        </div>
                    </div>

                    <div class="career-tags">
                        ${[...new Set(personalizedCareers)].slice(0, 6).map(career => 
                            `<span class="career-tag tooltip" data-tooltip="Click to learn more">${career}</span>`
                        ).join('')}
                    </div>

                    <div style="background: linear-gradient(135deg, #f7fafc, #edf2f7); padding: 20px; border-radius: 12px; margin: 20px 0;">
                        <h4 style="color: #2d3748; margin-bottom: 10px;">💡 Key Skills to Develop:</h4>
                        <p style="color: #4a5568; font-size: 14px;">${careerData.skills.join(' • ')}</p>
                    </div>

                    <div class="action-buttons">
                        <button class="btn btn-download" onclick="downloadResults()">📊 Download Report</button>
                        <button class="btn btn-restart" onclick="location.reload()">🔄 Retake Quiz</button>
                        <a href="applicant.php" class="btn btn-dashboard">🏠 Dashboard</a>
                    </div>
                </div>
            `;
        }

        function downloadResults() {
            // Create a simple text report
            const maxScore = Math.max(...Object.values(scores));
            const primaryPath = Object.keys(scores).find(key => scores[key] === maxScore);
            const careerData = careerDatabase[primaryPath];
            
            const total = Object.values(scores).reduce((a, b) => a + b, 0);
            const report = `
CAREER ASSESSMENT RESULTS
========================
Generated: ${new Date().toLocaleDateString()}

Primary Career Path: ${careerData.title}
Description: ${careerData.description}

Score Breakdown:
- Technical: ${Math.round((scores.tech / total) * 100)}%
- Hybrid: ${Math.round((scores.mixed / total) * 100)}%
- People-Focused: ${Math.round((scores.nonTech / total) * 100)}%
- Leadership: ${Math.round((scores.leadership / total) * 100)}%

Recommended Careers:
${careerData.careers.map(career => `• ${career}`).join('\n')}

Key Skills to Develop:
${careerData.skills.map(skill => `• ${skill}`).join('\n')}

Target Industries:
${careerData.industries.map(industry => `• ${industry}`).join('\n')}

Next Steps:
1. Research the recommended career paths
2. Identify skill gaps and create a learning plan
3. Network with professionals in your target field
4. Consider relevant certifications or additional training
5. Update your resume to highlight relevant skills

Assessment completed with AI-powered analysis.
            `.trim();

            const blob = new Blob([report], { type: 'text/plain' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `career-assessment-${new Date().toISOString().split('T')[0]}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            showNotification("Report downloaded successfully! 📊", "success");
        }

        function showNotification(message, type = "info") {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 16px 24px;
                border-radius: 12px;
                color: white;
                font-weight: 600;
                z-index: 10000;
                animation: slideInRight 0.3s ease-out;
                max-width: 300px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            `;

            const colors = {
                success: 'linear-gradient(135deg, #48bb78, #38a169)',
                warning: 'linear-gradient(135deg, #ed8936, #dd6b20)',
                info: 'linear-gradient(135deg, #4299e1, #3182ce)'
            };

            notification.style.background = colors[type] || colors.info;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease-in forwards';
                setTimeout(() => document.body.removeChild(notification), 300);
            }, 3000);
        }

        // Add CSS animations for notifications
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);

        // Add keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && selectedOption !== null) {
                if (currentQuestion === questions.length - 1) {
                    submitQuiz();
                } else {
                    goNext();
                }
            } else if (e.key === 'ArrowLeft' && currentQuestion > 0) {
                goPrev();
            } else if (e.key >= '1' && e.key <= '5') {
                const options = document.querySelectorAll('.option');
                const index = parseInt(e.key) - 1;
                if (options[index]) {
                    options.forEach(o => o.classList.remove('selected'));
                    options[index].classList.add('selected');
                    selectedOption = parseInt(options[index].getAttribute('data-value'));
                }
            }
        });

        // Add career tag click functionality
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('career-tag')) {
                const career = e.target.textContent;
                showCareerModal(career);
            }
        });

        function showCareerModal(career) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10000;
                animation: fadeIn 0.3s ease-out;
            `;

            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: white;
                padding: 30px;
                border-radius: 16px;
                max-width: 500px;
                width: 90%;
                text-align: center;
                animation: scaleIn 0.3s ease-out;
            `;

            // Simple career information (in real app, this would come from a database)
            const careerInfo = {
                'Software Engineer': 'Design, develop, and maintain software applications and systems.',
                'Data Scientist': 'Analyze complex data to help organizations make informed decisions.',
                'Product Manager': 'Guide the development and strategy of products from conception to launch.',
                'UX Designer': 'Create user-friendly interfaces and improve user experience.',
                'Project Manager': 'Plan, execute, and oversee projects to ensure successful completion.'
            };

            modalContent.innerHTML = `
                <h3 style="color: #1a202c; margin-bottom: 15px;">${career}</h3>
                <p style="color: #4a5568; line-height: 1.6; margin-bottom: 20px;">
                    ${careerInfo[career] || 'Learn more about this exciting career opportunity and how it aligns with your skills and interests.'}
                </p>
                <button onclick="this.parentElement.parentElement.remove()" 
                        style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    Close
                </button>
            `;

            modal.appendChild(modalContent);
            document.body.appendChild(modal);

            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Add additional CSS for modal animations
        const modalStyle = document.createElement('style');
        modalStyle.textContent = `
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes scaleIn {
                from { transform: scale(0.8); opacity: 0; }
                to { transform: scale(1); opacity: 1; }
            }
        `;
        document.head.appendChild(modalStyle);
    </script>
</body>
</html>