<?php
use Postvak\Mail\AutoReply;
use Postvak\Mail\RuleEngine;

function msg(array $over = []): array
{
    return $over + [
        'uid' => 1, 'subject' => 'Factuur oktober', 'from' => ['name' => 'Zonneplan', 'email' => 'noreply@zonneplan.nl'],
        'to' => [['name' => 'Ton', 'email' => 'ton@labee.eu']], 'cc' => [], 'date' => gmdate(DATE_ATOM), 'message_id' => '<a@b>', 'raw' => '',
    ];
}

test('conditions: contains, is, ends with, not contains, any field', function () {
    $rule = fn ($c, $all = true) => ['id' => 1, 'enabled' => true, 'match_all' => $all, 'conditions' => $c, 'actions' => ['move' => 'X', 'trash' => false, 'read' => false, 'flag' => false]];
    assertTrue(RuleEngine::matches($rule([['field' => 'from', 'op' => 'contains', 'value' => 'ZONNEPLAN']]), msg()));
    assertTrue(RuleEngine::matches($rule([['field' => 'from', 'op' => 'ends_with', 'value' => '@zonneplan.nl']]), msg()));
    assertTrue(RuleEngine::matches($rule([['field' => 'to', 'op' => 'is', 'value' => 'ton@labee.eu']]), msg()));
    assertSame(false, RuleEngine::matches($rule([['field' => 'subject', 'op' => 'not_contains', 'value' => 'factuur']]), msg()));
    assertTrue(RuleEngine::matches($rule([['field' => 'any', 'op' => 'contains', 'value' => 'oktober']]), msg()));
    // all vs any
    $two = [['field' => 'from', 'op' => 'contains', 'value' => 'zonneplan'], ['field' => 'subject', 'op' => 'contains', 'value' => 'aanbieding']];
    assertSame(false, RuleEngine::matches($rule($two), msg()));
    assertTrue(RuleEngine::matches($rule($two, false), msg()));
});

test('plan: flags add up, the first move wins, disabled rules are skipped', function () {
    $r = fn ($id, $actions, $enabled = true) => ['id' => $id, 'enabled' => $enabled, 'match_all' => true,
        'conditions' => [['field' => 'any', 'op' => 'contains', 'value' => 'zonneplan']],
        'actions' => $actions + ['move' => null, 'trash' => false, 'read' => false, 'flag' => false]];
    $plan = RuleEngine::plan([$r(1, ['read' => true]), $r(2, ['move' => 'INBOX.Energie']), $r(3, ['move' => 'INBOX.Anders', 'flag' => true]), $r(4, ['flag' => true], false)], [msg(), msg(['uid' => 2, 'from' => ['name' => 'Iemand', 'email' => 'x@y.nl'], 'subject' => 'Hoi'])]);
    assertSame([1], array_keys($plan));
    assertSame(['read' => true, 'flag' => false, 'labels' => [], 'move' => 'INBOX.Energie', 'trash' => false, 'rules' => [1, 2]], $plan[1]);
});

test('label action: labels from several rules add up', function () {
    $r = fn ($id, $label) => ['id' => $id, 'enabled' => true, 'match_all' => true, 'conditions' => [['field' => 'any', 'op' => 'contains', 'value' => 'zonneplan']],
        'actions' => ['move' => null, 'trash' => false, 'read' => false, 'flag' => false, 'label' => $label]];
    $plan = RuleEngine::plan([$r(1, '$label1'), $r(2, 'pv_energie'), $r(3, '$label1')], [msg()]);
    assertSame(['$label1', 'pv_energie'], $plan[1]['labels']);
    assertSame('$label2', RuleEngine::validate(['name' => 'x', 'conditions' => [['field' => 'from', 'op' => 'contains', 'value' => 'a']], 'actions' => ['label' => '$label2']])['actions']['label']);
});

test('rule validation', function () {
    assertThrows(fn () => RuleEngine::validate(['name' => 'x', 'conditions' => [['field' => 'from', 'op' => 'contains', 'value' => 'a']], 'actions' => []]));
    assertThrows(fn () => RuleEngine::validate(['name' => 'x', 'conditions' => [['field' => 'body', 'op' => 'contains', 'value' => 'a']], 'actions' => ['read' => true]]));
    assertThrows(fn () => RuleEngine::validate(['name' => 'x', 'conditions' => [['field' => 'from', 'op' => 'contains', 'value' => 'a']], 'actions' => ['move' => 'INBOX.A']]));
    $ok = RuleEngine::validate(['name' => ' Zonneplan ', 'account_id' => '3', 'conditions' => [['field' => 'from', 'op' => 'contains', 'value' => 'zonneplan']], 'actions' => ['move' => 'INBOX.A', 'trash' => true]]);
    assertSame('Zonneplan', $ok['name']);
    assertSame(3, $ok['account_id']);
    assertSame(null, $ok['actions']['move']); // trash wins
});

test('out-of-office: who gets a reply', function () {
    $own = ['ton@labee.eu'];
    $person = msg(['from' => ['name' => 'Peter', 'email' => 'peter@example.org']]);
    assertTrue(AutoReply::eligible($person, 'ton@labee.eu', $own));
    assertSame(false, AutoReply::eligible(msg(), 'ton@labee.eu', $own)); // noreply@
    assertSame(false, AutoReply::eligible($person + [], 'info@labee.eu', $own)); // not addressed to this account
    assertSame(false, AutoReply::eligible(['raw' => "List-Id: <x.y>\r\n"] + $person, 'ton@labee.eu', $own));
    assertSame(false, AutoReply::eligible(['raw' => "Precedence: bulk\r\n"] + $person, 'ton@labee.eu', $own));
    assertSame(false, AutoReply::eligible(['raw' => "Auto-Submitted: auto-replied\r\n"] + $person, 'ton@labee.eu', $own));
    assertTrue(AutoReply::eligible(['raw' => "Auto-Submitted: no\r\n"] + $person, 'ton@labee.eu', $own));
    assertSame(false, AutoReply::eligible(['date' => gmdate(DATE_ATOM, time() - 5 * 86400)] + $person, 'ton@labee.eu', $own));
    assertSame(false, AutoReply::eligible(msg(['from' => ['name' => 'Ik', 'email' => 'ton@labee.eu']]), 'ton@labee.eu', $own));
});

test('out-of-office: period', function () {
    $s = ['enabled' => true, 'starts_on' => '2026-10-20', 'ends_on' => '2026-11-01', 'subject' => '', 'body' => 'Weg', 'contacts_only' => false];
    assertSame(false, AutoReply::active($s, new DateTimeImmutable('2026-10-19')));
    assertTrue(AutoReply::active($s, new DateTimeImmutable('2026-10-20')));
    assertTrue(AutoReply::active($s, new DateTimeImmutable('2026-11-01')));
    assertSame(false, AutoReply::active($s, new DateTimeImmutable('2026-11-02')));
    assertSame(false, AutoReply::active(['body' => ' '] + $s, new DateTimeImmutable('2026-10-25')));
});
