# One-off (7 Nov 2026): strings for app build 1.16.11+47.
# App-only (T): attendance saved, class now / next class, "Left" as a user status.
# Gibbon core '{count} min' (empty in every Gibbon language): added to each LANG_final.py FINAL.
import pprint, re, sys
A = {  # lang: (Attendance saved!, Class now: {class}, Next class: {class}, Left (user status), {count} min)
 'af_ZA': ('Bywoning gestoor!', 'Klas nou: {class}', 'Volgende klas: {class}', 'Vertrek', '{count} min'),
 'ar_SA': ('تم حفظ الحضور!', 'الحصة الحالية: {class}', 'الحصة التالية: {class}', 'غادر', '{count} د'),
 'ca_ES': ('Assistència desada!', 'Classe ara: {class}', 'Classe següent: {class}', 'Baixa', '{count} min'),
 'de_DE': ('Anwesenheit gespeichert!', 'Jetzt: {class}', 'Als Nächstes: {class}', 'Ausgeschieden', '{count} Min.'),
 'el_GR': ('Οι παρουσίες αποθηκεύτηκαν!', 'Τώρα: {class}', 'Επόμενο: {class}', 'Αποχώρησε', '{count} λεπ.'),
 'es_ES': ('¡Asistencia guardada!', 'Clase actual: {class}', 'Próxima clase: {class}', 'Retirado', '{count} min'),
 'es_MX': ('¡Asistencia guardada!', 'Clase actual: {class}', 'Próxima clase: {class}', 'Retirado', '{count} min'),
 'es_DO': ('¡Asistencia guardada!', 'Clase actual: {class}', 'Próxima clase: {class}', 'Retirado', '{count} min'),
 'fr_FR': ('Présences enregistrées !', 'En ce moment : {class}', 'Prochain cours : {class}', 'Parti', '{count} min'),
 'he_IL': ('הנוכחות נשמרה!', 'עכשיו: {class}', 'השיעור הבא: {class}', 'עזב/ה', '{count} דק׳'),
 'hr_HR': ('Prisutnost spremljena!', 'Sada: {class}', 'Sljedeći sat: {class}', 'Otišao', '{count} min'),
 'it_IT': ('Presenze salvate!', 'Ora: {class}', 'Prossima lezione: {class}', 'Cessato', '{count} min'),
 'ja_JP': ('出欠を保存しました！', '現在の授業：{class}', '次の授業：{class}', '退籍', '{count}分'),
 'lt_LT': ('Lankomumas išsaugotas!', 'Dabar: {class}', 'Kita pamoka: {class}', 'Išėjęs', '{count} min.'),
 'nl_NL': ('Aanwezigheid opgeslagen!', 'Nu: {class}', 'Volgende les: {class}', 'Vertrokken', '{count} min'),
 'pl_PL': ('Frekwencja zapisana!', 'Teraz: {class}', 'Następne zajęcia: {class}', 'Odszedł', '{count} min'),
 'pt_BR': ('Frequência salva!', 'Aula agora: {class}', 'Próxima aula: {class}', 'Desligado', '{count} min'),
 'pt_PT': ('Assiduidade guardada!', 'Aula agora: {class}', 'Próxima aula: {class}', 'Saiu', '{count} min'),
 'ro_RO': ('Prezența a fost salvată!', 'Acum: {class}', 'Ora următoare: {class}', 'Plecat', '{count} min'),
 'sq_AL': ('Frekuentimi u ruajt!', 'Tani: {class}', 'Ora e radhës: {class}', 'Larguar', '{count} min'),
 'th_TH': ('บันทึกการมาเรียนแล้ว!', 'ตอนนี้: {class}', 'คาบถัดไป: {class}', 'ลาออก', '{count} นาที'),
 'tr_TR': ('Yoklama kaydedildi!', 'Şu anki ders: {class}', 'Sonraki ders: {class}', 'Ayrıldı', '{count} dk'),
 'uk_UA': ('Відвідуваність збережено!', 'Зараз: {class}', 'Наступне заняття: {class}', 'Вибув', '{count} хв'),
 'ur_PK': ('حاضری محفوظ ہو گئی!', 'ابھی: {class}', 'اگلی کلاس: {class}', 'چلا گیا', '{count} منٹ'),
 'vi_VN': ('Đã lưu điểm danh!', 'Đang học: {class}', 'Tiết tiếp theo: {class}', 'Rời trường', '{count} phút'),
 'zh_CN': ('考勤已保存！', '当前课程：{class}', '下一节课：{class}', '离校', '{count} 分钟'),
 'zh_HK': ('出席紀錄已儲存！', '現正上課：{class}', '下一節課：{class}', '離校', '{count} 分鐘'),
}
KEYS = ['Attendance saved!', 'Class now: {class}', 'Next class: {class}', 'Left (user status)']
for L, vals in A.items():
    src = open(f'{L}.py', encoding='utf-8').read()
    b = {}; exec(src, b)
    T = dict(b['T']); T.update(zip(KEYS, vals[:4]))
    src, n = re.subn(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
    assert n == 1, L
    open(f'{L}.py', 'w', encoding='utf-8').write(src)
    c = {}; exec(src, c); assert c['T']['Left (user status)'] == vals[3] and c.get('EXTRA') == b.get('EXTRA') and c['P'] == b['P'], L
    f = open(f'{L}_final.py', encoding='utf-8').read()
    if "'{count} min':" not in f:
        assert f.rstrip().endswith('}'), L
        f = f.rstrip()[:-1] + f"{'{count} min'!r}: {vals[4]!r},   # Gibbon: empty (planner lesson length)\n}}\n"
        open(f'{L}_final.py', 'w', encoding='utf-8').write(f)
    print(L, 'ok')
