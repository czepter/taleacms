<?php

// Job openings (2.11): a page for each job with JobPosting data and an application form with a CV. The item's "true until"
// (Core\Validity) is the closing date – after it the job hides itself and its address leads to the jobs page. Applications
// are enquiries with their own retention (Core\Jobs).
return [
    'name' => 'Job openings',
    'button' => 'New job openings',
    'description' => 'Location, employment type, salary, description, requirements and an application form with a CV on each job page; a job hides itself after its closing date.',
    'order' => 60,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['location', 'Location', 'text'],
        ['employment_type', 'Employment type', 'text'],
        ['salary_min', 'Salary from', 'number'],
        ['salary_max', 'Salary to', 'number'],
        ['salary_unit', 'Salary unit', 'text'],
        ['description', 'Description', 'html'],
        ['requirements', 'Requirements', 'html'],
        ['we_offer', 'We offer', 'html'],
        ['contact', 'Contact', 'polozka', ['preset' => 'people']],
        ['start_date', 'Start date', 'datum'],
    ],
    'schema' => ['type' => 'JobPosting', 'pole' => ['description' => 'description', 'employmentType' => 'employment_type', 'jobLocation' => 'location',
        'baseSalary' => 'salary_min', 'baseSalaryMax' => 'salary_max', 'salaryUnit' => 'salary_unit']],
    'claude' => 'One item per job. ALWAYS set valid_until to the closing date (the application deadline): after it the job hides itself, its address leads to the jobs page, '
        . 'and search engines get validThrough – without it the site audit lists the job (kind job). A Collection list of it on the jobs page (newest first); the item page '
        . 'carries the job text and a "Job application" form with a CV, whose submissions are in Enquiries. employment_type as text (full-time, part-time, contract, temporary, internship – recognised for structured data); '
        . 'salary_unit "per month" or "per hour". Salaries appear in structured data only with the collection currency: set it in Collections → structured data, or with update_collection schema_org {"type":"JobPosting","fields":{…the current mapping from list_collections…},"currency":"EUR"}. '
        . 'The contact field links a job to a person when the site has a Team collection. Applications are personal data: Enquiries → "Delete job applications after" sets how long they are kept.',
    'list' => ['sort' => 'newest'],
    'card' => ['location', 'employment_type'],
    'template' => function (array $fields): array {
        $n = \Kaleta\Builder\Build::fresh(...);
        $types = array_column($fields, 'type', 'key');
        // one line per fact; a text field that the administrator removed is left out
        $fact = fn (string $key, string $label, string $tags): ?array => isset($types[$key]) ? $n('text', ['html' => '<p><strong>' . e(t($label)) . ':</strong> ' . $tags . '</p>']) : null;
        $section = fn (string $key, string $label): array => isset($types[$key]) ? [['tag' => 'h2'] + $n('heading', ['text' => t($label)]), $n('text', ['html' => '{{' . $key . '}}'])] : [];

        return array_values(array_filter([
            ['tag' => 'h1'] + $n('heading', ['text' => '{{name}}']),
            $fact('location', 'Location', '{{location}}'),
            $fact('employment_type', 'Employment type', '{{employment_type}}'),
            isset($types['salary_min'], $types['salary_max']) ? $fact('salary_min', 'Salary', '{{salary_min}}–{{salary_max}} {{salary_unit}}') : null,
            $fact('start_date', 'Start date', '{{start_date}}'),
            $fact('contact', 'Contact', '{{contact}}'),
            isset($types['description']) ? $n('text', ['html' => '{{description}}']) : null,
            ...$section('requirements', 'Requirements'),
            ...$section('we_offer', 'We offer'),
            ['tag' => 'h2'] + $n('heading', ['text' => t('Apply for this job')]),
            // the hidden field carries the job name: {{nazev}} is filled on the item page and comes back with the form (Front\Forms)
            $n('form', ['name' => t('Job application'), 'button_text' => t('Send application'), 'thank_you' => t('Thank you for your application. We will get back to you.'), 'fields' => [
                ['label' => t('Name'), 'type' => 'text', 'required' => true],
                ['label' => t('Email'), 'type' => 'email', 'required' => true],
                ['label' => t('Phone'), 'type' => 'tel', 'required' => false],
                ['label' => t('CV'), 'type' => 'file', 'required' => true],
                ['label' => t('A few words about you'), 'type' => 'textarea', 'required' => false],
                ['label' => t('I agree to the processing of my personal data for the purpose of this selection procedure.'), 'type' => 'checkbox', 'required' => true],
                ['label' => t('Job opening'), 'type' => 'hidden', 'value' => '{{name}}'],
            ]]),
        ]));
    },
];
