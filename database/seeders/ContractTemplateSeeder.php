<?php

namespace Database\Seeders;

use App\Models\ContractTemplate;
use Illuminate\Database\Seeder;

class ContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Employment Agreement',
                'description' => 'Standard employment agreement covering terms, compensation, and expectations.',
                'type' => 'standard',
                'content' => $this->getEmploymentAgreement(),
            ],
            [
                'name' => 'Non-Disclosure Agreement (NDA)',
                'description' => 'Standard non-disclosure agreement protecting company and customer information.',
                'type' => 'standard',
                'content' => $this->getNda(),
            ],
            [
                'name' => 'Non-Compete Agreement',
                'description' => 'Non-compete agreement for the painting industry.',
                'type' => 'standard',
                'content' => $this->getNonCompete(),
            ],
            [
                'name' => 'Background Check Consent',
                'description' => 'Authorization for pre-employment background check.',
                'type' => 'standard',
                'content' => $this->getBackgroundCheckConsent(),
            ],
        ];

        foreach ($templates as $template) {
            ContractTemplate::query()->updateOrCreate(
                ['name' => $template['name']],
                [
                    ...$template,
                    'content_type' => 'rich_text',
                    'merge_tags' => ContractTemplate::getAvailableMergeTags(),
                    'is_active' => true,
                    'version' => 1,
                ],
            );
        }
    }

    private function getEmploymentAgreement(): string
    {
        return <<<'CONTENT'
EMPLOYMENT AGREEMENT

This Employment Agreement ("Agreement") is entered into as of {date} by and between {company_name}, located at {company_address} ("Company"), and {employee_name} ("Employee").

1. POSITION AND DUTIES

The Company agrees to employ {employee_name} as {job_title} in the {department} department, beginning on {hire_date}. Employee Number: {employee_number}. The Employee agrees to perform all duties associated with this position in a professional and competent manner, and to comply with all Company policies and procedures.

2. COMPENSATION

The Employee will be compensated at a rate of {pay_rate} ({pay_type}). Payment will be made in accordance with the Company's standard payroll schedule. The Company reserves the right to adjust compensation based on performance reviews and market conditions.

3. AT-WILL EMPLOYMENT

This employment is "at-will," meaning either the Company or the Employee may terminate the employment relationship at any time, with or without cause and with or without notice. Nothing in this Agreement guarantees employment for any specific period.

4. CONFIDENTIALITY

The Employee agrees to maintain the confidentiality of all proprietary information, customer data, pricing structures, and business strategies learned during the course of employment. This obligation survives the termination of employment.

5. ACKNOWLEDGMENT

By signing below, both parties acknowledge that they have read, understand, and agree to the terms of this Employment Agreement.
CONTENT;
    }

    private function getNda(): string
    {
        return <<<'CONTENT'
NON-DISCLOSURE AGREEMENT

This Non-Disclosure Agreement ("Agreement") is entered into as of {date} by and between {company_name} ("Company") and {employee_name}, Employee #{employee_number} ("Employee").

1. CONFIDENTIAL INFORMATION

The Employee acknowledges that during the course of employment as {job_title}, they will have access to confidential information including but not limited to: customer lists and contact information, pricing formulas and profit margins, proprietary painting techniques and processes, vendor relationships and pricing, business plans and financial data, and employee compensation information.

2. OBLIGATIONS

The Employee agrees to: (a) hold all confidential information in strict confidence; (b) not disclose confidential information to any third party without prior written consent from the Company; (c) use confidential information solely for the purpose of performing job duties; (d) return all confidential materials upon termination of employment.

3. DURATION

The obligations of confidentiality under this Agreement shall survive the termination of employment and remain in effect for a period of two (2) years following the date of termination.

4. REMEDIES

The Employee acknowledges that any breach of this Agreement may cause irreparable harm to the Company, and the Company shall be entitled to seek injunctive relief in addition to any other remedies available at law.

By signing below, the Employee acknowledges that they have read, understand, and agree to the terms of this Non-Disclosure Agreement.
CONTENT;
    }

    private function getNonCompete(): string
    {
        return <<<'CONTENT'
NON-COMPETE AGREEMENT

This Non-Compete Agreement ("Agreement") is entered into as of {date} by and between {company_name} ("Company") and {employee_name}, Employee #{employee_number} ("Employee"), employed as {job_title} in the {department} department.

1. NON-COMPETE COVENANT

During the term of employment and for a period of twelve (12) months following termination, the Employee agrees not to: (a) directly or indirectly engage in a competing painting business within a 25-mile radius of the Company's service area; (b) solicit or attempt to solicit any customers of the Company for competing painting services; (c) recruit or attempt to recruit any employees or contractors of the Company.

2. CONSIDERATION

The Employee acknowledges that this Agreement is supported by adequate consideration, including continued employment and access to the Company's confidential information, customer relationships, and specialized training.

3. REASONABLENESS

Both parties agree that the restrictions contained in this Agreement are reasonable in scope, duration, and geographic area, and are necessary to protect the Company's legitimate business interests.

4. SEVERABILITY

If any provision of this Agreement is found to be unenforceable, the remaining provisions shall remain in full force and effect, and the court may modify any unenforceable provision to make it enforceable to the maximum extent permitted by law.

By signing below, the Employee acknowledges that they have read, understand, and agree to the terms of this Non-Compete Agreement.
CONTENT;
    }

    private function getBackgroundCheckConsent(): string
    {
        return <<<'CONTENT'
BACKGROUND CHECK CONSENT AND AUTHORIZATION

This Background Check Consent ("Consent") is provided by {employee_name}, Employee #{employee_number}, to {company_name} as of {date}.

1. AUTHORIZATION

I, {employee_name}, hereby authorize {company_name} to conduct a background investigation as part of the employment process for the position of {job_title}. This investigation may include verification of criminal history, driving records, employment history, education, and professional references.

2. DISCLOSURE

I understand that a consumer report and/or investigative consumer report may be obtained. This disclosure is provided in compliance with the Fair Credit Reporting Act (FCRA). I have the right to request a complete and accurate disclosure of the nature and scope of the investigation, and to receive a copy of any consumer report obtained.

3. ONGOING AUTHORIZATION

I further authorize periodic background checks throughout the duration of my employment, as permitted by applicable law. I understand that I will be notified before any subsequent checks are conducted.

4. ADVERSE ACTION RIGHTS

If any information obtained may result in an adverse employment decision, I will be provided with a pre-adverse action notice, a copy of the report, and a summary of my rights under the FCRA before a final decision is made.

By signing below, I voluntarily consent to the background investigation described above.
CONTENT;
    }
}
