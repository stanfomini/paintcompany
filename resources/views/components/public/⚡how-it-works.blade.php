<?php

use App\Models\CompanySetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.guest')] #[Title('How It Works | AI Color Visualization, Instant Pricing & Digital Everything | Painting Made Easy Denver')] class extends Component
{
    #[Computed]
    public function company(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function structuredData(): string
    {
        $company = $this->company;
        $name = $company->company_name ?? 'Painting Made Easy Denver';

        $howTo = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            'name' => 'How to Get Your Home Painted with Painting Made Easy Denver',
            'description' => 'The complete digital painting experience — from AI color visualization and instant pricing to real-time project tracking and digital payment. Here is how it works step by step.',
            'step' => [
                [
                    '@type' => 'HowToStep',
                    'position' => 1,
                    'name' => 'Visualize Your Colors with AI',
                    'text' => 'Upload a photo of your room or home exterior to our free AI color visualizer. Choose any color from Sherwin-Williams, Benjamin Moore, Behr, or PPG and see a realistic preview of the finished result instantly. Try as many colors as you want.',
                    'url' => url('/visualize'),
                ],
                [
                    '@type' => 'HowToStep',
                    'position' => 2,
                    'name' => 'Get an Instant Price Quote',
                    'text' => 'Select your rooms, pick a size, and see an accurate price in seconds. Our pricing engine calculates every surface — walls, ceiling, baseboards, doors — using industry production rates. Or text us and our SMS bot walks you through your project step by step.',
                    'url' => url('/'),
                ],
                [
                    '@type' => 'HowToStep',
                    'position' => 3,
                    'name' => 'Review and Sign Your Digital Proposal',
                    'text' => 'Receive a digital proposal you can open on any device. Browse line items, choose add-on options, select between packages, and view project photos. Sign electronically right on the page — no printing, no scanning, no PDFs emailed back and forth.',
                ],
                [
                    '@type' => 'HowToStep',
                    'position' => 4,
                    'name' => 'Confirm Colors and Schedule',
                    'text' => 'Through your personal customer portal, confirm your paint colors and preferred start date. Our bidirectional system means both you and the crew agree on everything before work begins. Nothing moves forward without your approval.',
                ],
                [
                    '@type' => 'HowToStep',
                    'position' => 5,
                    'name' => 'Track Your Project in Real Time',
                    'text' => 'Follow your project through five clear stages in your customer portal. View daily progress photos, chat with your crew, review prep plans, and see exactly where your project stands at every moment. Full transparency from start to finish.',
                ],
                [
                    '@type' => 'HowToStep',
                    'position' => 6,
                    'name' => 'Pay Securely Online',
                    'text' => 'Receive a digital invoice and pay online with credit card or bank transfer through secure Stripe checkout. No checks, no cash. Your final before-and-after photos are saved in your portal.',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return $howTo;
    }
};
?>

<x-slot:structuredData>{!! $this->structuredData !!}</x-slot:structuredData>

<div>
    @section('meta_description', 'See how Painting Made Easy Denver transforms house painting with AI color visualization, instant digital pricing, SMS project management, digital proposals with e-signatures, and real-time customer portal tracking.')
    @section('canonical', route('how-it-works'))
    @section('robots', 'index, follow')

    {{-- Hero --}}
    <section class="bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white pt-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 pt-16 sm:pt-24 pb-12 sm:pb-16 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight mb-6 leading-tight">How Painting Made Easy Denver Works</h1>
            <p class="text-lg sm:text-xl text-blue-100 max-w-3xl mx-auto leading-relaxed">AI color visualization. Instant digital pricing. Real-time project tracking. A complete digital experience that makes painting your home faster, easier, and completely transparent.</p>
        </div>
    </section>

    {{-- Step 1: Color Visualizer --}}
    <section class="bg-white py-16 sm:py-20" id="color-visualizer">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-start gap-6">
                <div class="shrink-0 w-14 h-14 rounded-2xl bg-blue-100 flex items-center justify-center">
                    <span class="text-2xl font-extrabold text-blue-600">1</span>
                </div>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-4">See Your Colors Before You Commit</h2>
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6">Upload a photo of your room or home exterior to our <strong>free AI color visualizer</strong>. Choose any color from Sherwin-Williams, Benjamin Moore, Behr, or PPG. Our AI generates a realistic preview showing exactly what your space will look like — preserving textures, lighting, and architectural details.</p>
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6">Try as many colors as you want. Compare options side by side. Find the perfect shade before a single brush stroke. No guessing, no tiny swatches on the wall — see the full transformation instantly.</p>
                    <a href="{{ route('visualize') }}" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-xl transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                        Try the Color Visualizer Free
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Step 2: Instant Pricing --}}
    <section class="bg-zinc-50 py-16 sm:py-20" id="instant-pricing">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-start gap-6">
                <div class="shrink-0 w-14 h-14 rounded-2xl bg-emerald-100 flex items-center justify-center">
                    <span class="text-2xl font-extrabold text-emerald-600">2</span>
                </div>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-4">Get an Accurate Price in Seconds</h2>
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6">Select your rooms, pick a size, and our pricing engine calculates an accurate quote instantly. We don't guess — our system uses <strong>industry production rates</strong> for every surface type: walls, ceiling, baseboards, doors. It factors in coats, prep time, materials, and labor to give you a real number, not a ballpark.</p>
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6">Prefer to text? Send us a message and our <strong>SMS bot</strong> walks you through your project step by step — collecting photos, room details, and preferences. You can also request a traditional in-home estimate. However you prefer to get started, we make it easy.</p>
                    <div class="flex flex-wrap gap-4">
                        <a href="{{ route('home') }}#get-estimate" class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-6 py-3 rounded-xl transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Get Instant Pricing
                        </a>
                        <a href="{{ route('booking.general') }}" class="inline-flex items-center gap-2 bg-white hover:bg-zinc-100 text-zinc-900 font-bold px-6 py-3 rounded-xl border border-zinc-300 transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                            Book an Estimate
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Step 3: Digital Proposal --}}
    <section class="bg-white py-16 sm:py-20" id="digital-proposals">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-start gap-6">
                <div class="shrink-0 w-14 h-14 rounded-2xl bg-violet-100 flex items-center justify-center">
                    <span class="text-2xl font-extrabold text-violet-600">3</span>
                </div>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-4">Review and Sign Your Proposal Digitally</h2>
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6">We send you a link to your <strong>interactive digital proposal</strong> — open it on your phone, tablet, or computer. See every line item, browse project photos, and choose the options that fit your needs.</p>
                    <ul class="space-y-3 text-zinc-600 text-lg mb-6">
                        <li class="flex items-start gap-3">
                            <svg class="w-6 h-6 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span><strong>Packages</strong> — Choose between good, better, and best options</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-6 h-6 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span><strong>Add-ons</strong> — Select optional upgrades with transparent pricing</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-6 h-6 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span><strong>E-signature</strong> — Sign electronically right on the page, no printing needed</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-6 h-6 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span><strong>Photos</strong> — View annotated project photos and color selections</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Step 4: Confirm Colors & Schedule --}}
    <section class="bg-zinc-50 py-16 sm:py-20" id="confirmation">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-start gap-6">
                <div class="shrink-0 w-14 h-14 rounded-2xl bg-amber-100 flex items-center justify-center">
                    <span class="text-2xl font-extrabold text-amber-600">4</span>
                </div>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-4">Confirm Your Colors and Schedule</h2>
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6">Once you sign your proposal, you get access to your <strong>personal customer portal</strong>. Here you confirm paint colors and your preferred start date through our <strong>bidirectional confirmation system</strong> — both you and the crew agree on everything before work begins.</p>
                    <p class="text-lg text-zinc-600 leading-relaxed">Want to change a color? Reschedule? Do it right in the portal. Nothing moves forward without mutual agreement. You are always in control.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Step 5: Real-Time Tracking --}}
    <section class="bg-white py-16 sm:py-20" id="project-tracking">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-start gap-6">
                <div class="shrink-0 w-14 h-14 rounded-2xl bg-sky-100 flex items-center justify-center">
                    <span class="text-2xl font-extrabold text-sky-600">5</span>
                </div>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-4">Track Your Project in Real Time</h2>
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6">Your customer portal shows you exactly where your project stands at every moment. Our <strong>five-stage tracking system</strong> takes you from estimate acceptance through completion:</p>
                    <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-6">
                        @foreach(['Accepted' => 'Estimate signed', 'Colors' => 'Colors & date confirmed', 'Prep' => 'Prep plan approved', 'Painting' => 'Crew at work', 'Complete' => 'Final walkthrough'] as $stage => $desc)
                            <div class="bg-sky-50 rounded-xl p-4 text-center">
                                <div class="font-bold text-sky-700 text-sm">{{ $stage }}</div>
                                <div class="text-xs text-zinc-500 mt-1">{{ $desc }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-lg text-zinc-600 leading-relaxed">View daily progress photos, chat with your crew, approve prep plans, and see the transformation happen in real time. No wondering what is going on — you see everything.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Step 6: Payment --}}
    <section class="bg-zinc-50 py-16 sm:py-20" id="payment">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-start gap-6">
                <div class="shrink-0 w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center">
                    <span class="text-2xl font-extrabold text-green-600">6</span>
                </div>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-4">Pay Securely Online</h2>
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6">When your project is complete, receive a digital invoice and <strong>pay securely online</strong> with credit card or bank transfer through Stripe. No checks, no cash. Your before-and-after photos are saved in your portal forever.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Why We Built This --}}
    <section class="bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white py-16 sm:py-20">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-2xl sm:text-3xl font-bold mb-6">Why We Built This</h2>
            <p class="text-lg text-blue-100 leading-relaxed mb-6">We believe getting your home painted should be exciting, not stressful. So we built the technology to make it that way. Every tool — the AI color visualizer, the instant pricing, the digital proposals, the customer portal — exists because we asked: <em>how can we make this easier and more transparent for the homeowner?</em></p>
            <p class="text-lg text-blue-100 leading-relaxed mb-8">No other painting company in Denver offers this. Because no other painting company built it.</p>
            <a href="{{ route('home') }}#get-estimate" class="inline-flex items-center gap-3 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-xl">
                Get Started — It's Free
            </a>
        </div>
    </section>
</div>
