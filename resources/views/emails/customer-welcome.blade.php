<div style="font-family: Arial, Helvetica, sans-serif; color: #333; line-height: 1.6; max-width: 600px;">
    <p>Hi {{ $customer->first_name }},</p>

    <p>My name is {{ $assignee->name }} and I'll be your point of contact at {{ config('app.name') }}. Welcome aboard!</p>

    <p>Here's a quick look at how things work from here:</p>

    <ol style="padding-left: 20px;">
        <li><strong>Estimate</strong> &mdash; I'll put together a detailed proposal for your project.</li>
        <li><strong>Review &amp; Sign</strong> &mdash; You'll receive a link to review and approve the estimate online.</li>
        <li><strong>Scheduling</strong> &mdash; We'll pick colors together and lock in a date that works for you.</li>
        <li><strong>Job Completion</strong> &mdash; Our crew takes care of everything, and I'll keep you updated along the way.</li>
    </ol>

    <p>If you have any questions at all, just reply to this email &mdash; it comes straight to me.</p>

    <p>Looking forward to working with you!</p>

    <p>
        Best,<br>
        {{ $assignee->name }}
    </p>
</div>
