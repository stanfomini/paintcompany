<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

class CrewContractSeeder extends Seeder
{
    public function run(): void
    {
        $settings = CompanySetting::getInstance();

        $settings->update([
            // Contract templates
            'crew_colorado_compliance_contract' => $this->getColoradoComplianceContract(),
            'crew_contractor_agreement' => $this->getContractorAgreement(),
            'crew_background_check_consent' => $this->getBackgroundCheckConsent(),
            // Pitch page defaults
            'crew_pitch_hourly_rate' => 25.00,
            'crew_pitch_job_sqft' => 2000,
            'crew_pitch_job_price' => 4000.00,
            'crew_pitch_paint_cost' => 400.00,
            'crew_pitch_bonus_percent' => 5.00,
        ]);
    }

    private function getColoradoComplianceContract(): string
    {
        return <<<'CONTRACT'
COLORADO INDEPENDENT CONTRACTOR COMPLIANCE AGREEMENT

This Independent Contractor Compliance Agreement ("Agreement") is entered into by and between the Company and the Contractor (identified by digital signature below).

1. INDEPENDENT CONTRACTOR STATUS

The Contractor acknowledges and agrees that they are an independent contractor and not an employee of the Company. This classification is made in accordance with the Internal Revenue Service (IRS) guidelines and Colorado Revised Statutes § 8-40-202.

Under Colorado law (C.R.S. § 8-40-202), an independent contractor is defined as a person who:
(a) Is free from control and direction in the performance of the service, both under the contract and in fact;
(b) Is customarily engaged in an independent trade, occupation, profession, or business related to the service performed; and
(c) Performs work outside the usual course of business of the employer or performs work outside all of the places of business of the employer.

2. IRS 20-FACTOR TEST ACKNOWLEDGMENT

The Contractor acknowledges familiarity with the IRS 20-factor test for determining worker classification and confirms that the nature of the working relationship satisfies independent contractor status, including but not limited to:
- The Contractor controls the manner and means of completing work
- The Contractor provides their own tools and equipment
- The Contractor is free to work for other clients
- The Contractor assumes the risk of profit or loss
- The Contractor sets their own schedule within project parameters

3. WORKERS' COMPENSATION INSURANCE

In accordance with Colorado Workers' Compensation Act (C.R.S. § 8-40-101 et seq.), the Contractor shall:
(a) Maintain their own workers' compensation insurance coverage if they have any employees;
(b) Provide proof of coverage upon request;
(c) Understand that as an independent contractor, they are not covered under the Company's workers' compensation policy;
(d) Acknowledge their right to obtain their own workers' compensation coverage.

4. LIABILITY INSURANCE

The Contractor shall maintain general liability insurance with minimum coverage of $1,000,000 per occurrence and $2,000,000 aggregate. Proof of insurance shall be provided prior to commencing any work.

5. TAX OBLIGATIONS

The Contractor acknowledges sole responsibility for:
(a) Payment of all federal, state, and local taxes, including self-employment tax;
(b) Quarterly estimated tax payments as required by the IRS;
(c) Colorado state income tax obligations;
(d) Any applicable local/city taxes;
(e) The Company will issue a Form 1099-NEC for payments of $600 or more in a calendar year.

6. COMPLIANCE WITH COLORADO LAW

The Contractor agrees to comply with all applicable Colorado laws and regulations, including but not limited to:
(a) Colorado Wage Claim Act (C.R.S. § 8-4-101 et seq.) as applicable to independent contractors;
(b) Colorado Anti-Discrimination Act (C.R.S. § 24-34-401 et seq.);
(c) All applicable building codes and safety regulations;
(d) Environmental protection regulations related to painting services (lead paint, VOC regulations).

7. DISPUTE RESOLUTION

Any disputes arising under this Agreement shall be resolved in accordance with Colorado law, with venue in the county where the Company's principal office is located.

By signing below, the Contractor acknowledges that they have read, understand, and agree to the terms of this Colorado Independent Contractor Compliance Agreement.
CONTRACT;
    }

    private function getContractorAgreement(): string
    {
        return <<<'CONTRACT'
CONTRACTOR AGREEMENT

This Contractor Agreement ("Agreement") is entered into by and between the Company and the Contractor (identified by digital signature below).

1. SCOPE OF WORK

The Contractor agrees to provide painting and related services through the Company's platform. Work assignments will be offered on a project-by-project basis, and the Contractor has the right to accept or decline any assignment.

2. PLATFORM USAGE

(a) The Contractor agrees to use the Company's scheduling and communication platform for all project-related coordination.
(b) The Contractor shall maintain an active profile with current contact information, insurance documentation, and availability.
(c) The Contractor shall respond to job offers within 24 hours when possible.
(d) The Contractor shall update job status in the platform as work progresses.

3. PAY STRUCTURE

(a) The Contractor will be compensated based on the agreed-upon rate for each project, which is determined using the Company's pricing formula.
(b) Payment will be issued within 7 business days of verified job completion.
(c) The Contractor may be eligible for performance bonuses as outlined in individual job agreements.
(d) The Contractor is responsible for providing their own tools, equipment, and transportation.

4. QUALITY STANDARDS

The Contractor agrees to:
(a) Perform all work in a professional and workmanlike manner;
(b) Follow industry best practices for surface preparation, priming, and painting;
(c) Use only Company-approved or customer-specified paint products;
(d) Complete work within the agreed-upon timeline;
(e) Address any customer concerns or touch-ups promptly;
(f) Maintain a clean and safe work environment;
(g) Protect customer property, furniture, and landscaping.

5. CODE OF CONDUCT

The Contractor shall:
(a) Treat all customers, team members, and Company staff with professionalism and respect;
(b) Arrive on time for scheduled work;
(c) Maintain a professional appearance while on job sites;
(d) Refrain from the use of alcohol or illegal substances while on job sites;
(e) Not solicit Company customers for private work;
(f) Maintain confidentiality of customer information and Company pricing.

6. SCHEDULING & AVAILABILITY

(a) The Contractor shall communicate availability through the platform;
(b) Once a job is accepted, the Contractor is expected to complete it unless mutually agreed otherwise;
(c) Cancellations with less than 48 hours notice may affect future job priority;
(d) The Contractor may set their own work hours within the project's scheduling requirements.

7. SAFETY

The Contractor shall:
(a) Follow all OSHA safety guidelines applicable to painting operations;
(b) Use appropriate personal protective equipment (PPE);
(c) Maintain current safety training certifications as required;
(d) Report any job site hazards or accidents immediately.

8. TERMINATION

(a) Either party may terminate this Agreement with 14 days written notice;
(b) Immediate termination may occur for safety violations, customer complaints of a serious nature, or breach of the Code of Conduct;
(c) Outstanding payments for completed work will be honored regardless of termination;
(d) All Company-provided materials must be returned upon termination.

9. NON-SOLICITATION

During the term of this Agreement and for 12 months after termination, the Contractor agrees not to directly solicit customers originally introduced through the Company's platform for private painting services.

10. ENTIRE AGREEMENT

This Agreement, together with the Colorado Contractor Compliance Agreement and Background Check Consent, constitutes the entire agreement between the parties. Modifications must be in writing and signed by both parties.

By signing below, the Contractor acknowledges that they have read, understand, and agree to the terms of this Contractor Agreement.
CONTRACT;
    }

    private function getBackgroundCheckConsent(): string
    {
        return <<<'CONTRACT'
BACKGROUND CHECK CONSENT AND AUTHORIZATION

DISCLOSURE AND AUTHORIZATION FOR BACKGROUND INVESTIGATION

IMPORTANT — PLEASE READ CAREFULLY BEFORE SIGNING

DISCLOSURE

In connection with your engagement as an independent contractor, the Company may obtain a consumer report and/or investigative consumer report about you from a consumer reporting agency. This may include information regarding your:

- Criminal history (felony and misdemeanor records)
- Driving record
- Identity verification
- Professional references

This disclosure is provided in compliance with the Fair Credit Reporting Act (FCRA), 15 U.S.C. § 1681 et seq., and the Colorado Consumer Credit Reporting Act (C.R.S. § 5-18-101 et seq.).

AUTHORIZATION

By signing below, I hereby authorize the Company and its designated agents to:

1. Obtain a consumer report and/or investigative consumer report about me for the purpose of evaluating my suitability as an independent contractor;

2. Contact references, employers, and educational institutions to verify information provided;

3. Conduct periodic updated reports throughout the duration of our working relationship, as permitted by law.

YOUR RIGHTS UNDER FEDERAL LAW (FCRA)

You have the right to:
(a) Request disclosure of the nature and scope of any investigation requested;
(b) Receive a copy of any consumer report obtained;
(c) Dispute inaccurate or incomplete information in your report;
(d) Have the consumer reporting agency reinvestigate disputed items within 30 days;
(e) Have inaccurate information corrected or deleted;
(f) Be informed if information from the report is used against you.

YOUR RIGHTS UNDER COLORADO LAW

Under the Colorado Chance to Compete Act (C.R.S. § 8-2-130) and related provisions:
(a) A criminal record does not automatically disqualify you from contracting opportunities;
(b) The Company will consider the nature of the offense, time elapsed, and relevance to the work to be performed;
(c) You will be given an opportunity to explain any adverse findings before a final decision is made.

PRE-ADVERSE AND ADVERSE ACTION NOTICE

If information obtained in a background check may result in an adverse decision regarding your contractor engagement, you will be provided with:
(a) A pre-adverse action notice with a copy of the consumer report;
(b) A copy of "A Summary of Your Rights Under the Fair Credit Reporting Act";
(c) A reasonable opportunity to dispute the accuracy of the information;
(d) A final adverse action notice if the decision stands, including the name and contact information of the consumer reporting agency.

I acknowledge that I have read and understand this Disclosure and Authorization, and I voluntarily consent to the background investigation described above.

By signing below, I authorize the Company to conduct a background check as described in this consent form.
CONTRACT;
    }
}
