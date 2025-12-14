<?php
session_start();

if (isset($_GET['ajax']) && isset($_GET['template'])) {
    $allowedTemplates = [
        "template_1",
        "template_2", 
        "template_3",
        "template_4",
        "template_5",
        "template_6",
        "template_7",
        "template_8"
    ];
    $template = $_GET['template'];
    if (in_array($template, $allowedTemplates)) {
        $templatesDir = __DIR__ . '/templates';
        if (!is_dir($templatesDir)) {
            mkdir($templatesDir, 0755, true);
        }
        
        $file = $templatesDir . "/{$template}.txt";
        if (file_exists($file)) {
            header('Content-Type: text/plain; charset=utf-8');
            readfile($file);
            exit;
        } else {
            $sampleContent = "This is sample content for {$template}.\n\n";
            $sampleContent .= "You can replace this by creating a file at:\n";
            $sampleContent .= "templates/{$template}.txt\n\n";
            $sampleContent .= "Sample template content:\n";
            $sampleContent .= "=======================\n";
            $sampleContent .= "Title: {$template}\n";
            $sampleContent .= "Date: " . date('Y-m-d') . "\n";
            $sampleContent .= "Content: This is where your template content would go.\n";
            $sampleContent .= "You can add any text, code, or formatting you need here.";
            
            header('Content-Type: text/plain; charset=utf-8');
            echo $sampleContent;
            exit;
        }
    }
    http_response_code(404);
    echo "Template not found.";
    exit;
}
ob_start();
include 'navbar.php';
$navbar = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Template Loader - MewBin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.0.1/dist/darkly/bootstrap.min.css" referrerpolicy="no-referrer"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.12.0/css/all.min.css" referrerpolicy="no-referrer"/>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <style>
        body {
            background: #000000 !important;
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        
        body #particles-js {
            position: fixed !important;
            width: 100% !important;
            height: 100% !important;
            top: 0 !important;
            left: 0 !important;
            z-index: -1 !important;
            background: linear-gradient(to bottom, rgba(5, 5, 8, 0.95) 0%, #000000 100%) !important;
            opacity: 1 !important;
            display: block !important;
        }
        
        #particles-js canvas {
            -webkit-filter: drop-shadow(0 0 8px #8a2be2) drop-shadow(0 0 16px #8a2be2) !important;
            filter: drop-shadow(0 0 8px #8a2be2) drop-shadow(0 0 16px #8a2be2) !important;
            will-change: filter;
            pointer-events: none;
        }

        .gradient-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background:radial-gradient(circle at 10% 20%, rgba(42, 0, 51, 0.2) 0%, transparent 20%), radial-gradient(circle at 90% 80%, rgba(90, 13, 122, 0.15) 0%, transparent 20%), radial-gradient(circle at 50% 50%, rgba(58, 0, 82, 0.1) 0%, transparent 50%);
            z-index: -1 !important;
            pointer-events: none !important;
            opacity: 1 !important;
        }

        .template-loader-container {
            max-width: 700px;
            margin: 80px auto 40px auto;
            background: rgba(13, 13, 13, 0.85);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6);
            padding: 36px 32px;
            border: 1px solid #333;
            position: relative;
            z-index: 1;
        }

        .template-loader-title {
            font-size: 2.2rem;
            font-weight: 700;
            background: linear-gradient(90deg, #8a2be2, #ff99ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 24px;
            text-align: center;
            text-shadow: 0 0 20px rgba(138, 43, 226, 0.3);
        }

        .form-label {
            color: #fff;
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .form-control, textarea {
            background: #111 !important;
            color: #fff !important;
            border: 1.5px solid #333 !important;
            border-radius: 8px;
            margin-bottom: 20px;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus, textarea:focus {
            background: #181818 !important;
            border-color: #8a2be2 !important;
            outline: none;
            box-shadow: 0 0 15px rgba(138, 43, 226, 0.4) !important;
            transform: scale(1.02);
        }

        .btn-primary {
            background: linear-gradient(90deg, #8a2be2 0%, #ff99ff 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            border-radius: 8px;
            padding: 12px 32px;
            font-size: 1.1em;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 10px;
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, #ff99ff 0%, #8a2be2 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(138, 43, 226, 0.4);
        }

        .template-preview {
            background: #111;
            color: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-top: 24px;
            min-height: 120px;
            font-family: 'Fira Mono', monospace;
            white-space: pre-wrap;
            word-break: break-word;
            border: 1px solid #333;
            transition: all 0.3s ease;
            cursor: pointer;
            max-height: 400px;
            overflow-y: auto;
        }

        .template-preview:hover {
            border-color: #8a2be2;
            box-shadow: 0 0 15px rgba(138, 43, 226, 0.3);
        }

        .template-loader-footer {
            text-align: center;
            margin-top: 32px;
            color: #aaa;
            font-size: 0.95em;
            line-height: 1.5;
        }

        .info-icon {
            color: #8a2be2;
            margin-right: 5px;
        }

        .tip-text {
            color: #ff99ff;
            font-weight: 600;
        }

        .loading-spinner {
            display: none;
            text-align: center;
            margin: 10px 0;
            color: #8a2be2;
        }

        .success-message {
            background: #1e7e34 !important;
            border-color: #1e7e34 !important;
            text-align: center;
            font-weight: bold;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .template-loader-container {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        select option {
            background: #111;
            color: #fff;
            padding: 10px;
        }
    </style>
</head>
<body>
    <div id="particles-js"></div>
    <div class="gradient-overlay"></div>
    
    <?= $navbar ?>
    
    <div class="template-loader-container">
        <div class="template-loader-title">
            <i class="fas fa-paste"></i> Template Loader
        </div>
        
        <form id="templateLoaderForm" autocomplete="off">
            <div class="mb-3">
                <label for="templateSelect" class="form-label">Select a Template</label>
                <select class="form-control" id="templateSelect" required>
                    <option value="">-- Choose a template --</option>
                    <option value="template_1">Template 1</option>
                    <option value="template_2">Template 2</option>
                    <option value="template_3">Template 3</option>
                    <option value="template_4">Template 4</option>
                    <option value="template_5">Template 5</option>
                    <option value="template_6">Template 6</option>
                    <option value="template_7">Template 7</option>
                    <option value="template_8">Template 8</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
            
            <div class="mb-3" id="customTemplateBox" style="display:none;">
                <label for="customTemplate" class="form-label">Custom Template</label>
                <textarea class="form-control" id="customTemplate" rows="5" placeholder="Paste your custom template here..."></textarea>
            </div>
            
            <div class="loading-spinner" id="loadingSpinner">
                <i class="fas fa-spinner fa-spin"></i> Loading template...
            </div>
            
            <button type="button" class="btn btn-primary" id="loadTemplateBtn">
                <i class="fas fa-download"></i> Load Template
            </button>
        </form>
        
        <div class="template-preview" id="templatePreview" style="display:none;"></div>
        
        <div class="template-loader-footer">
            <i class="fas fa-info-circle info-icon"></i> Select a template and click "Load Template" to preview and copy it.<br>
            <span class="tip-text">Tip:</span> Click inside the preview to copy the template to your clipboard.
        </div>
    </div>

    <script>
        try {
            if (typeof particlesJS !== 'undefined') {
                particlesJS("particles-js", {
                    particles: {
                        number: { value: 60, density: { enable: true, value_area: 800 } },
                        color: { value: "#8a2be2" },
                        shape: { type: "circle" },
                        opacity: { value: 0.3, random: true },
                        size: { value: 2, random: true },
                        line_linked: {
                            enable: true,
                            distance: 120,
                            color: "#8a2be2",
                            opacity: 0.2,
                            width: 1
                        },
                        move: {
                            enable: true,
                            speed: 1.5,
                            direction: "none",
                            random: true,
                            straight: false,
                            out_mode: "out"
                        }
                    }
                });
            }
        } catch (e) {
            console.log('Particles.js not available or failed to load');
        }

        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded - initializing template loader');

            const templateSelect = document.getElementById('templateSelect');
            const customBox = document.getElementById('customTemplateBox');
            const customTemplate = document.getElementById('customTemplate');
            const preview = document.getElementById('templatePreview');
            const loadBtn = document.getElementById('loadTemplateBtn');
            const loadingSpinner = document.getElementById('loadingSpinner');
            
            console.log('Elements loaded:', {
                templateSelect: !!templateSelect,
                customBox: !!customBox,
                customTemplate: !!customTemplate,
                preview: !!preview,
                loadBtn: !!loadBtn,
                loadingSpinner: !!loadingSpinner
            });

            function loadTemplateFile(templateName, callback) {
                console.log('Attempting to load template:', templateName);

                loadingSpinner.style.display = 'block';
                preview.style.display = 'none';

                fetch('template_loader.php?ajax=1&template=' + encodeURIComponent(templateName))
                    .then(response => {
                        console.log('Response status:', response.status);
                        if (!response.ok) {
                            throw new Error('Server returned: ' + response.status);
                        }
                        return response.text();
                    })
                    .then(content => {
                        console.log('Template loaded successfully, length:', content.length);
                        loadingSpinner.style.display = 'none';
                        callback(content, true);
                    })
                    .catch(error => {
                        console.error('Error loading template:', error);
                        loadingSpinner.style.display = 'none';
                        callback('Error: Could not load template. ' + error.message, false);
                    });
            }
            function handleLoadClick() {
                console.log('Load button clicked');
                
                const selectedTemplate = templateSelect.value;
                console.log('Selected template:', selectedTemplate);
                
                if (!selectedTemplate) {
                    alert('Please select a template first.');
                    return;
                }
                
                if (selectedTemplate === 'custom') {
                    const customContent = customTemplate.value.trim();
                    if (!customContent) {
                        alert('Please enter your custom template content.');
                        return;
                    }
                    console.log('Loading custom template');
                    preview.textContent = customContent;
                    preview.style.display = 'block';
                } else {
                    console.log('Loading file template:', selectedTemplate);
                    loadTemplateFile(selectedTemplate, function(content, success) {
                        preview.textContent = content;
                        preview.style.display = 'block';
                        if (!success) {
                            preview.style.borderColor = '#dc3545';
                        }
                    });
                }
            }

            function handleTemplateChange() {
                const value = this.value;
                console.log('Template changed to:', value);
                
                if (value === 'custom') {
                    customBox.style.display = 'block';
                } else {
                    customBox.style.display = 'none';
                }
				
                preview.style.display = 'none';
                preview.textContent = '';
                loadingSpinner.style.display = 'none';
            }

            function handlePreviewClick() {
                if (!preview.textContent || preview.style.display === 'none') {
                    return;
                }
                
                const textToCopy = preview.textContent;
                
                navigator.clipboard.writeText(textToCopy).then(() => {
                    console.log('Text copied to clipboard');
					
                    const originalContent = preview.textContent;
                    const originalBackground = preview.style.background;
                    const originalBorderColor = preview.style.borderColor;
                    
                    preview.textContent = '✓ Copied to clipboard!';
                    preview.classList.add('success-message');
                    
                    setTimeout(() => {
                        preview.textContent = originalContent;
                        preview.classList.remove('success-message');
                        preview.style.background = originalBackground;
                        preview.style.borderColor = originalBorderColor;
                    }, 1500);
                    
                }).catch(err => {
                    console.error('Failed to copy: ', err);
                    alert('Failed to copy to clipboard. Please select and copy the text manually.');
                });
            }
            if (loadBtn) {
                loadBtn.addEventListener('click', handleLoadClick);
                console.log('Load button event listener attached');
            }
            
            if (templateSelect) {
                templateSelect.addEventListener('change', handleTemplateChange);
                console.log('Template select event listener attached');
            }
            
            if (preview) {
                preview.addEventListener('click', handlePreviewClick);
                console.log('Preview event listener attached');
            }

            const testOption = document.createElement('option');
            testOption.value = 'test';
            testOption.textContent = 'Test Template';
            templateSelect.appendChild(testOption);
            
            console.log('Template loader initialized successfully');
        });

        setTimeout(() => {
            if (!document.getElementById('loadTemplateBtn').onclick) {
                console.log('Using fallback initialization');
                document.getElementById('loadTemplateBtn').onclick = function() {
                    const templateSelect = document.getElementById('templateSelect');
                    const customTemplate = document.getElementById('customTemplate');
                    const preview = document.getElementById('templatePreview');
                    
                    if (templateSelect.value === 'custom') {
                        preview.textContent = customTemplate.value || 'Please enter custom template content';
                    } else {
                        preview.textContent = 'This is a test template content for ' + templateSelect.value + '\n\nYou can create actual template files in the templates/ directory.';
                    }
                    preview.style.display = 'block';
                };
            }
        }, 1000);
    </script>
</body>
</html>