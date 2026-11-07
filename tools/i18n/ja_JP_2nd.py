# One-off: second pass on ja_JP (7 Nov 2026). Writes ja_JP_final.py; updates T/OVERRIDES in ja_JP.py.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('jajp_cur.json', encoding='utf-8'))
NEW = {
 # Roles, statuses and module names
 'Teacher': '教員', 'Teachers': '教員', 'Teacher - Left': '教員 - 退職', 'Student - Left': '生徒 - 退籍',
 'Support Staff': '事務・支援職員', 'Departure Reason': '退籍理由',
 'Admin': '管理ツール', 'System Admin': 'システム管理', 'School Admin': '学校管理',
 'Finance': '会計', 'Update Finance Data': '会計データの更新', 'Messenger': 'メッセージ配信',
 'Crowd Assessment': '相互評価', 'Formal Assessment': '外部評価', 'Individual Needs': '個別支援',
 'Tracking': '学習状況の追跡', 'Admissions': '入学受付', 'Behaviour': '行動記録',
 'Activities': '課外活動', 'Cancel Activities?': '課外活動を中止しますか？',
 # Report cards, homework, lessons
 'Report': '通知表', 'Reports': '通知表', 'View Reports': '通知表を見る',
 'Due Date': '提出期限', 'Due Soon': 'まもなく提出期限', 'No upcoming due dates!': '近日中の提出期限はありません！',
 'Descriptor': '評価の記述', 'Timing Change': '時程の変更', 'Message Wall': '掲示板',
 'Not Taken': '未記録', 'Partial': '一部出席',
 # Medical
 'Condition': '疾患', 'Condition Name': '疾患名', 'Medical Conditions': '疾患・持病', 'Add Medical Condition': '疾患を追加',
 'New Condition': '新しい疾患', 'Check the box to add a new medical condition': '新しい疾患を追加する場合はチェックを入れてください',
 'This student has one or more {level} risk medical conditions.': 'この生徒には、リスク「{level}」の疾患が1つ以上あります。',
 'Last Episode Date': '最終発症日', 'Last Episode Treatment': '最終発症時の処置',
 # Contact details and addresses
 'Home': '自宅', 'Work': '職場', 'Home Address': '自宅住所', 'Home Address (Country)': '自宅住所（国）',
 'Contact Information': '連絡先情報', 'Contact 1 Relationship': '連絡先1 続柄', 'Contact 2 Relationship': '連絡先2 続柄',
 'Country of Birth': '出生国', 'Next School': '進学先', 'Other': 'その他', 'Miscellaneous': 'その他の情報',
 'Personal': '個人情報', 'N/A': '該当なし',
 'Do not contact by email.': 'メールでの連絡はご遠慮ください。',
 "Access data on family's children?": '家族の子どものデータにアクセス可？',
 'In an emergency, please try and contact the adult family members listed below first. If these cannot be reached, then try the emergency contacts below.': '緊急時には、まず下記の成人のご家族に連絡してください。連絡がつかない場合は、下記の緊急連絡先にご連絡ください。',
 'These details are used when immediate family members (e.g. parent, spouse) cannot be reached first. Please try to avoid listing immediate family members.': 'これらの情報は、近親者（例：親、配偶者）に連絡が取れない場合に使用されます。近親者はなるべく記載しないでください。',
 # Data updates and forms
 'Update Family Data': '家族データの更新', 'Update Staff Data': '教職員データの更新',
 'Include all staff, regardless of status, start date, end date, etc.': 'ステータス、開始日、終了日などに関係なく、すべての教職員を含めます。',
 'Include all students, regardless of status and current enrolment. Some data may not display.': 'ステータスや現在の登録状況に関係なく、すべての生徒を含めます。一部のデータが表示されない場合があります。',
 'User will be prompted on next login.': '次回ログイン時に変更を求められます。',
 'Impersonate User': '他のユーザーとしてログイン', 'Submit': '送信', 'Summary': '概要', 'Invoice Total': '請求合計',
 'If the specified company is not paying all fees, which categories are they paying?': '指定の会社がすべての料金を支払わない場合、どのカテゴリーの料金を支払いますか？',
 'Should the family be sent a copy of billing emails?': '家族にも請求メールのコピーを送りますか？',
}
WHY = {
 'Teacher': '先生 (form of address; role label is 教員)', 'Support Staff': 'サポートスタッフ (help-desk staff)',
 'Admin': 'システム管理 in the earlier draft, same as System Admin', 'System Admin': 'システム管理者 (a person)', 'School Admin': '学校管理者 (a person)',
 'Finance': '財務, while the app says 会計 everywhere else', 'Messenger': 'メッセンジャー (a chat app)',
 'Crowd Assessment': '群衆評価 ("mob assessment")', 'Formal Assessment': '正式な評価 (the module is for external exams)',
 'Tracking': 'トラッキング (web tracking)', 'Report': 'レポート (essay/report); the app uses 通知表',
 'Due Date': '期限 (any deadline)', 'Descriptor': '記述子 (a programming term)', 'Condition': '症状 (symptom)',
 'Home': 'ホーム (home page); phone type', 'Work': '仕事 (a job); phone type', 'Contact Information': '連絡先, same as Contact',
 'Other': '他', 'Not Taken': '未取得 (not acquired)', 'Submit': '提出 (hand in homework); form button',
 'Include all students, regardless of status and current enrolment. Some data may not display.': '学生 (university student)',
 'These details are used when immediate family members (e.g. parent, spouse) cannot be reached first. Please try to avoid listing immediate family members.': 'mixed 肉親 / 直系 for the same term',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('ja_JP_final.py', 'w', encoding='utf-8') as f:
    f.write("# ja_JP: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's ja_JP file.\n"
            "# ホームルーム (form group), クラス, 科目, 学年, 年度, 学期, 担任, 教員 (role), 教職員, 教科領域, 通知表, 提出期限, 会計, 疾患.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon/earlier: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
src = open('ja_JP.py', encoding='utf-8').read()
b = {}; exec(src, b)
assert not re.search(r'^(T|P|GAPS|OVERRIDES|EXTRA)\[', src, re.M)
T = dict(b['T'])
T['Cancel new condition'] = '新しい疾患の追加を取り消す'
T['{count} due'] = '提出期限 {count}件'
src = re.sub(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
src = re.sub(r'^OVERRIDES = \{.*?\n\}\n', lambda _: 'OVERRIDES = ' + pprint.pformat({k: v for k, v in b['OVERRIDES'].items() if k not in FINAL}, width=200) + '\n', src, count=1, flags=re.S | re.M)
src = src.replace("担任 (tutor), 通知表 (report card), 行動 (behaviour).", "担任 (tutor), 通知表 (report card), 行動 (behaviour), 教員 (Teacher role),\n# 提出期限 (due date), 会計 (finance), 疾患 (medical condition) since the 7 Nov 2026 pass.")
open('ja_JP.py', 'w', encoding='utf-8').write(src)
c = {}; exec(src, c); print('ok', c['OVERRIDES'], c['EXTRA'] == b['EXTRA'], c['T']['{count} due'])
