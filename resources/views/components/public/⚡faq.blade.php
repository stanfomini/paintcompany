<?php

use App\Models\CompanySetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.guest')] #[Title('Frequently Asked Questions | Painting Made Easy Denver')] class extends Component
{
    #[Computed]
    public function company(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function faqs(): array
    {
        return [
            [
                'category' => 'Pricing & Estimates',
                'questions' => [
                    [
                        'q' => 'How much does it cost to paint a house in Denver?',
                        'a' => 'Interior painting in Denver typically ranges from $2,500 to $8,000 for an average home, depending on the number of rooms, prep work, and paint quality. Exterior projects range from $4,000 to $12,000. With our instant digital pricing tool, you can select your rooms, pick a size, and see an accurate price in seconds — calculated from industry production rates for every surface including walls, ceiling, baseboards, and doors.',
                    ],
                    [
                        'q' => 'How do I get a painting estimate?',
                        'a' => 'You have three options, all designed to be fast and easy. First, you can text us and our SMS bot walks you through your project step by step, collecting photos and details automatically. Second, you can use our website to get instant digital pricing by selecting rooms and sizes. Third, you can request a traditional in-home estimate. Most customers love the instant pricing because they see a real number in seconds, not days.',
                    ],
                    [
                        'q' => 'What does the digital estimate look like?',
                        'a' => 'You receive a digital proposal you can open on any device — phone, tablet, or computer. It shows every line item with detailed pricing, lets you choose add-on options, select between packages (good, better, best), and view project photos. When you\'re ready, sign electronically right on the page. No PDFs emailed back and forth, no printing, no scanning. It\'s a complete interactive experience.',
                    ],
                    [
                        'q' => 'How is your pricing calculated?',
                        'a' => 'Our pricing engine uses industry production rates for every surface type. When you select a room, we calculate the exact square footage of walls, ceiling, and trim, then apply production rates that account for the number of coats, surface condition, and prep time required. Materials, labor, and overhead are all factored in automatically. The result is an accurate, transparent price — not a rough guess.',
                    ],
                    [
                        'q' => 'Do you offer free estimates?',
                        'a' => 'Yes. Every estimate is free and comes with no obligation. You can get an instant digital quote on our website, request a virtual estimate via text message, or schedule an in-person walkthrough. We believe in transparency — you should know exactly what your project will cost before making any decisions.',
                    ],
                ],
            ],
            [
                'category' => 'AI Color Visualization',
                'questions' => [
                    [
                        'q' => 'Can I see what paint colors will look like on my walls before painting?',
                        'a' => 'Yes. Our free AI color visualizer lets you upload a photo of your room or home exterior, choose any color from Sherwin-Williams, Benjamin Moore, Behr, or PPG, and see a realistic AI-generated preview showing exactly what it will look like. The AI preserves textures, lighting, and shadows for a realistic result. No guessing, no tiny sample swatches — see the full transformation instantly.',
                    ],
                    [
                        'q' => 'How does the AI color visualizer work?',
                        'a' => 'Upload any photo of the space you want to paint. Our AI uses computer vision to identify the paintable surfaces, then applies your selected color while preserving the natural textures, lighting, and architectural details of the space. The result is a realistic preview that shows you exactly what the finished project will look like. It works for interior walls, exterior siding, cabinets, and more.',
                    ],
                    [
                        'q' => 'Is the color visualizer free to use?',
                        'a' => 'Yes. You can use our AI color visualizer for free directly on our website. Try different colors, compare options, and find the perfect shade before your project even starts. No account required for your first visualizations.',
                    ],
                    [
                        'q' => 'What paint brands are available in the color visualizer?',
                        'a' => 'Our color visualizer includes colors from Sherwin-Williams, Benjamin Moore, Behr, and PPG. You can search by color name, browse by brand, or enter a custom color. We use the same premium paint products for your actual project.',
                    ],
                ],
            ],
            [
                'category' => 'Project Tracking & Communication',
                'questions' => [
                    [
                        'q' => 'How do I track my painting project?',
                        'a' => 'Through your personal customer portal. We provide real-time project tracking with five clear stages: estimate acceptance, color and date confirmation, prep work, active painting, and completion. At each stage, you can view progress photos, confirm decisions, and chat directly with your crew. Both you and the painting team confirm every step before it moves forward — nothing happens without your approval.',
                    ],
                    [
                        'q' => 'Can I communicate with my painting crew directly?',
                        'a' => 'Yes. Your customer portal includes a direct chat feature where you can message your crew, share photos, and discuss details in real time. You can also communicate via text message through our SMS system. Every conversation is saved so you have a complete record of all project communications.',
                    ],
                    [
                        'q' => 'Will I see progress photos during my project?',
                        'a' => 'Yes. Your crew uploads progress photos throughout the project, and you can view them in real time through your customer portal. Before-and-after photos are captured for every project so you can see the full transformation.',
                    ],
                    [
                        'q' => 'How does the date and color confirmation process work?',
                        'a' => 'We use a bidirectional confirmation system. We propose a start date and paint colors, and you review and confirm them through your portal. Both sides must agree before anything moves forward. If you want to change a color or reschedule, you can do that directly in the portal. It keeps everyone on the same page.',
                    ],
                ],
            ],
            [
                'category' => 'The Painting Process',
                'questions' => [
                    [
                        'q' => 'How long does a painting project take?',
                        'a' => 'Most interior projects take 2 to 5 days depending on the number of rooms and prep work needed. Exterior projects typically take 3 to 7 days. You will see a detailed timeline in your digital proposal and can track daily progress through your customer portal.',
                    ],
                    [
                        'q' => 'Do I need to move my furniture?',
                        'a' => 'No. Our crew handles all furniture moving and protection as part of every job. We cover floors, move furniture to the center of the room, and put everything back when we are done. It is all included in your quote — no surprises.',
                    ],
                    [
                        'q' => 'What paint brands do you use?',
                        'a' => 'We use premium paints from Sherwin-Williams and Benjamin Moore. Our most popular choices include Sherwin-Williams Duration for exteriors and Benjamin Moore Regal Select for interiors. You can preview any color on your actual walls using our AI color visualizer before making a decision.',
                    ],
                    [
                        'q' => 'How do you handle prep work?',
                        'a' => 'Proper prep is the foundation of a great paint job. We assess every surface for its condition — our system even uses AI to detect surface types like drywall, wood, or stucco and adjusts prep requirements accordingly. Your prep plan is visible in your customer portal before we start, so you know exactly what will happen.',
                    ],
                    [
                        'q' => 'Are you licensed and insured?',
                        'a' => 'Yes. We are fully licensed, bonded, and insured in the state of Colorado. We carry general liability and workers compensation coverage on every job for your peace of mind.',
                    ],
                ],
            ],
            [
                'category' => 'Payment & Guarantees',
                'questions' => [
                    [
                        'q' => 'How does payment work?',
                        'a' => 'We offer digital invoicing through Stripe. Pay securely online with credit card or bank transfer — no checks, no cash, no hassle. You receive your invoice digitally and can pay from any device. A deposit is collected when you accept the estimate, with the balance due upon project completion.',
                    ],
                    [
                        'q' => 'What is included in your guarantee?',
                        'a' => 'Every project comes with our Cleaner Than We Found It guarantee. If you find any drips, missed spots, or mess after we leave, we come back and fix it at no charge. We also warranty our workmanship for two years.',
                    ],
                    [
                        'q' => 'Do you offer financing?',
                        'a' => 'We accept all major credit cards through our secure Stripe payment system, which allows you to use your card\'s built-in financing options. Contact us to discuss payment arrangements for larger projects.',
                    ],
                ],
            ],
            [
                'category' => 'Technology & Digital Experience',
                'questions' => [
                    [
                        'q' => 'What makes Painting Made Easy Denver different from other painters?',
                        'a' => 'Technology. We built a complete digital platform that transforms every step of the painting experience. AI color visualization shows you results before we start. Instant digital pricing gives you accurate quotes in seconds. Our SMS bot and customer portal keep you connected at every step. Digital proposals with e-signatures eliminate paperwork. Real-time project tracking with photo updates provides full transparency. No other painting company in Denver offers this level of technology and customer experience.',
                    ],
                    [
                        'q' => 'Do I need to download an app?',
                        'a' => 'No. Everything works in your web browser on any device — phone, tablet, or computer. Your customer portal, the color visualizer, digital proposals, and chat are all accessible through a web link. No app download required.',
                    ],
                    [
                        'q' => 'Can I text you instead of calling?',
                        'a' => 'Absolutely. Our SMS system is designed for text-first communication. Text us to start a project consultation, send photos, ask questions, or get updates. Our automated system guides you through the initial project scoping, and a real person is always available for complex questions.',
                    ],
                    [
                        'q' => 'How do virtual estimates work?',
                        'a' => 'Text us or submit photos through our website. Our system walks you through capturing the right photos and project details. From there, we use our digital pricing tools to calculate an accurate quote based on your room sizes, surface conditions, and chosen paint products. You receive a digital proposal you can review and sign from anywhere.',
                    ],
                ],
            ],
        ];
    }

    #[Computed]
    public function structuredData(): string
    {
        $questions = [];
        foreach ($this->faqs as $category) {
            foreach ($category['questions'] as $qa) {
                $questions[] = [
                    '@type' => 'Question',
                    'name' => $qa['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $qa['a'],
                    ],
                ];
            }
        }

        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $questions,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
};
?>

<x-slot:structuredData>{!! $this->structuredData !!}</x-slot:structuredData>

<div>
    @section('meta_description', 'Frequently asked questions about Painting Made Easy Denver. Learn about our AI color visualizer, instant digital pricing, real-time project tracking, digital proposals, and more.')
    @section('canonical', route('faq'))
    @section('robots', 'index, follow')

    {{-- Hero --}}
    <section class="bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white pt-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 pt-16 sm:pt-24 pb-12 sm:pb-16 text-center">
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight mb-6">Frequently Asked Questions</h1>
            <p class="text-lg sm:text-xl text-blue-100 max-w-2xl mx-auto">Everything you need to know about our painting services, AI color visualizer, instant pricing, project tracking, and the digital experience that makes us different.</p>
        </div>
    </section>

    {{-- FAQ Content --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            @foreach($this->faqs as $category)
                <div class="mb-12 last:mb-0">
                    <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-6 pb-3 border-b border-zinc-200">{{ $category['category'] }}</h2>
                    <div class="space-y-6">
                        @foreach($category['questions'] as $qa)
                            <details class="group bg-zinc-50 rounded-xl" x-data="{ open: false }" x-on:toggle="open = $el.open">
                                <summary class="flex items-center justify-between cursor-pointer p-6 font-semibold text-zinc-900 text-lg hover:text-blue-600 transition-colors list-none">
                                    <span>{{ $qa['q'] }}</span>
                                    <svg class="w-5 h-5 shrink-0 ml-4 text-zinc-400 transition-transform duration-200 group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </summary>
                                <div class="px-6 pb-6 text-zinc-600 leading-relaxed">
                                    {{ $qa['a'] }}
                                </div>
                            </details>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-zinc-50 py-16">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-4">Ready to Get Started?</h2>
            <p class="text-lg text-zinc-600 mb-8">Get an instant price quote, try our AI color visualizer, or text us to start your project.</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('home') }}#get-estimate" class="inline-flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                    Get Your Free Estimate
                </a>
                <a href="{{ route('visualize') }}" class="inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                    Try Color Visualizer
                </a>
            </div>
        </div>
    </section>
</div>
