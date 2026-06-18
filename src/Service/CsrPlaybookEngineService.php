<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RetentionOpportunity;

final class CsrPlaybookEngineService
{
    /**
     * @var array<string, array{
     *     type: string,
     *     title: string,
     *     purpose: string,
     *     openingPrompt: string,
     *     qualificationQuestions: list<string>,
     *     objectionHandlingNotes: list<string>,
     *     suggestedNextSteps: list<string>,
     *     complianceNotes: list<string>
     * }>
     */
    private const PLAYBOOKS = [
        \App\Entity\CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER => [
            'type' => \App\Entity\CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER,
            'title' => 'Maintenance Offer',
            'purpose' => 'Present a proactive maintenance plan or seasonal tune-up option.',
            'openingPrompt' => 'I noticed we have an opportunity to keep this equipment in better shape. Would it help if I walked you through a maintenance option that could reduce surprises?',
            'qualificationQuestions' => [
                'Is the customer trying to reduce breakdowns or service calls?',
                'Does the property have equipment that is overdue for routine maintenance?',
                'Would the customer value preferred scheduling or discounted service visits?',
            ],
            'objectionHandlingNotes' => [
                'If price is the concern, anchor on fewer emergency calls and longer equipment life.',
                'If timing is the concern, offer a short maintenance overview now and follow up later.',
            ],
            'suggestedNextSteps' => [
                'Confirm equipment coverage and last service date.',
                'Offer the relevant maintenance plan or tune-up visit.',
                'Document any follow-up date if the customer wants to decide later.',
            ],
            'complianceNotes' => [
                'Do not promise savings that are not documented in the plan.',
                'Keep the explanation factual and tied to current equipment condition.',
            ],
        ],
        \App\Entity\CsrPlaybookAttachment::TYPE_WARRANTY_DISCUSSION => [
            'type' => \App\Entity\CsrPlaybookAttachment::TYPE_WARRANTY_DISCUSSION,
            'title' => 'Warranty Discussion',
            'purpose' => 'Clarify warranty coverage, expiration timing, and customer expectations.',
            'openingPrompt' => 'I want to make sure you have the right warranty details for this equipment. Can I review what is covered and what is coming up next?',
            'qualificationQuestions' => [
                'What equipment is the customer asking about?',
                'Does the customer know when the warranty expires?',
                'Is there an active service issue that might be covered?',
            ],
            'objectionHandlingNotes' => [
                'If coverage is uncertain, avoid assumptions and verify the record.',
                'If the warranty has expired, explain the difference between coverage and paid service clearly.',
            ],
            'suggestedNextSteps' => [
                'Confirm the unit model, serial, and warranty date.',
                'Set expectations for any covered and non-covered work.',
                'Offer maintenance or replacement options if the coverage window is closing.',
            ],
            'complianceNotes' => [
                'Do not state that a repair is covered unless the record confirms it.',
                'Avoid suggesting warranty terms that are not in the file.',
            ],
        ],
        \App\Entity\CsrPlaybookAttachment::TYPE_REPLACEMENT_DISCUSSION => [
            'type' => \App\Entity\CsrPlaybookAttachment::TYPE_REPLACEMENT_DISCUSSION,
            'title' => 'Replacement Discussion',
            'purpose' => 'Guide the customer through an equipment replacement conversation.',
            'openingPrompt' => 'Based on the condition and age of the system, it may be time to talk about replacement options. Would you like a plain-language overview of what that could look like?',
            'qualificationQuestions' => [
                'Is the current equipment failing frequently or beyond its expected life?',
                'Does the customer want a repair-versus-replace comparison?',
                'Are there timing constraints that affect the replacement window?',
            ],
            'objectionHandlingNotes' => [
                'If the customer wants to delay, outline the risk of continued breakdowns without pressure.',
                'If budget is the concern, focus on staged decision making and clear next steps.',
            ],
            'suggestedNextSteps' => [
                'Confirm the customer wants a proposal or site review.',
                'Capture the replacement criteria the customer cares about most.',
                'Schedule the next touchpoint before ending the call.',
            ],
            'complianceNotes' => [
                'Keep the conversation factual and do not overstate equipment failure.',
                'Avoid implied financing terms unless those terms are actually available.',
            ],
        ],
        \App\Entity\CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION => [
            'type' => \App\Entity\CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION,
            'title' => 'Overdue Invoice Discussion',
            'purpose' => 'Discuss outstanding balances and payment expectations professionally.',
            'openingPrompt' => 'I’m calling about an outstanding balance on the account. Can we review the invoice together and see what is the best way to move it forward?',
            'qualificationQuestions' => [
                'Is the customer disputing the invoice or asking for more detail?',
                'Does the customer need the invoice resent or explained line by line?',
                'Is there a preferred payment method or date?',
            ],
            'objectionHandlingNotes' => [
                'If the customer disputes the balance, slow down and confirm the record before debating.',
                'If the customer needs time, agree on a specific follow-up date.',
            ],
            'suggestedNextSteps' => [
                'Confirm the amount due and the invoice number.',
                'Offer to resend the invoice or route to accounting if needed.',
                'Document the promised payment or callback date.',
            ],
            'complianceNotes' => [
                'Use respectful, factual language only.',
                'Do not threaten actions that are not approved by company policy.',
            ],
        ],
        \App\Entity\CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH => [
            'type' => \App\Entity\CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH,
            'title' => 'Dormant Customer Outreach',
            'purpose' => 'Re-engage customers who have gone quiet or have not scheduled recent work.',
            'openingPrompt' => 'It has been a while since we last connected, and I wanted to check in to see whether there is anything we can help you with this season.',
            'qualificationQuestions' => [
                'Has the customer had any recent equipment issues?',
                'Is the customer looking for seasonal maintenance or planning ahead?',
                'Would the customer like a quick check-in or a formal follow-up later?',
            ],
            'objectionHandlingNotes' => [
                'If the customer is busy, keep the message short and set a precise follow-up.',
                'If they say everything is fine, leave a simple door-open offer for future service.',
            ],
            'suggestedNextSteps' => [
                'Offer a maintenance review or a general account check-in.',
                'Capture the best follow-up window if they are not ready now.',
                'Record any new service needs that surface during the call.',
            ],
            'complianceNotes' => [
                'Do not imply the customer requested contact if they did not.',
                'Keep outreach respectful and easy to opt out of further discussion.',
            ],
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const OPPORTUNITY_RECOMMENDATIONS = [
        RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING => [
            \App\Entity\CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER,
        ],
        RetentionOpportunity::TYPE_WARRANTY_NEARING_EXPIRATION => [
            \App\Entity\CsrPlaybookAttachment::TYPE_WARRANTY_DISCUSSION,
            \App\Entity\CsrPlaybookAttachment::TYPE_REPLACEMENT_DISCUSSION,
        ],
        RetentionOpportunity::TYPE_OLD_EQUIPMENT => [
            \App\Entity\CsrPlaybookAttachment::TYPE_REPLACEMENT_DISCUSSION,
            \App\Entity\CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER,
        ],
        RetentionOpportunity::TYPE_OPEN_INVOICE => [
            \App\Entity\CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION,
        ],
        RetentionOpportunity::TYPE_DORMANT_CUSTOMER => [
            \App\Entity\CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH,
            \App\Entity\CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER,
        ],
        RetentionOpportunity::TYPE_NO_RECENT_SERVICE => [
            \App\Entity\CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER,
            \App\Entity\CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH,
        ],
        RetentionOpportunity::TYPE_NO_RECENT_CALLS => [
            \App\Entity\CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH,
        ],
    ];

    /**
     * @return list<array{
     *     type: string,
     *     title: string,
     *     purpose: string,
     *     openingPrompt: string,
     *     qualificationQuestions: list<string>,
     *     objectionHandlingNotes: list<string>,
     *     suggestedNextSteps: list<string>,
     *     complianceNotes: list<string>
     * }>
     */
    public function all(): array
    {
        return array_values(self::PLAYBOOKS);
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     purpose: string,
     *     openingPrompt: string,
     *     qualificationQuestions: list<string>,
     *     objectionHandlingNotes: list<string>,
     *     suggestedNextSteps: list<string>,
     *     complianceNotes: list<string>
     * }|null
     */
    public function get(string $playbookType): ?array
    {
        return self::PLAYBOOKS[$playbookType] ?? null;
    }

    /**
     * @param iterable<RetentionOpportunity> $opportunities
     *
     * @return list<string>
     */
    public function getRecommendedPlaybookTypes(iterable $opportunities): array
    {
        $recommended = [];
        foreach ($opportunities as $opportunity) {
            foreach (self::OPPORTUNITY_RECOMMENDATIONS[$opportunity->getOpportunityType()] ?? [] as $playbookType) {
                if (!in_array($playbookType, $recommended, true)) {
                    $recommended[] = $playbookType;
                }
            }
        }

        foreach (array_keys(self::PLAYBOOKS) as $playbookType) {
            if (!in_array($playbookType, $recommended, true)) {
                $recommended[] = $playbookType;
            }
        }

        return $recommended;
    }
}
