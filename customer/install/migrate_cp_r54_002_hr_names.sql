-- GAS-CP-R54-002 · leave-type names in all portal languages (foreign staff see the type in their language) and staff import from Excel.
-- Run once in phpMyAdmin with the portal database selected, after migrate_cp_r54_hr_requests.sql.
ALTER TABLE hr_leave_types
  ADD COLUMN name_el VARCHAR(120) NULL, ADD COLUMN name_it VARCHAR(120) NULL, ADD COLUMN name_bg VARCHAR(120) NULL, ADD COLUMN name_uk VARCHAR(120) NULL;
UPDATE hr_leave_types SET name_el = 'Κανονική άδεια', name_it = 'Ferie annuali', name_bg = 'Платен годишен отпуск', name_uk = 'Щорічна відпустка' WHERE code = 'ANN' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Αναρρωτική άδεια', name_it = 'Malattia', name_bg = 'Отпуск по болест', name_uk = 'Лікарняний' WHERE code = 'SICK' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Γονική άδεια', name_it = 'Congedo parentale', name_bg = 'Родителски отпуск', name_uk = 'Батьківська відпустка' WHERE code = 'PAR' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Άδεια πατρότητας', name_it = 'Congedo di paternità', name_bg = 'Отпуск по бащинство', name_uk = 'Відпустка для батька' WHERE code = 'PAT' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Απεργία', name_it = 'Sciopero', name_bg = 'Стачка', name_uk = 'Страйк' WHERE code = 'STR' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Άδεια γάμου', name_it = 'Congedo matrimoniale', name_bg = 'Отпуск за сключване на брак', name_uk = 'Відпустка на весілля' WHERE code = 'MAR' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Άδεια φροντιστή', name_it = 'Congedo per assistenza familiare', name_bg = 'Отпуск за полагане на грижи', name_uk = 'Відпустка по догляду' WHERE code = 'CAR' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Άδεια ανωτέρας βίας', name_it = 'Forza maggiore', name_bg = 'Отпуск при форсмажор', name_uk = 'Форс-мажор' WHERE code = 'FMJ' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Άδεια άνευ αποδοχών', name_it = 'Aspettativa non retribuita', name_bg = 'Неплатен отпуск', name_uk = 'Відпустка без збереження зарплати' WHERE code = 'UNP' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Ειδική άδεια', name_it = 'Permesso speciale', name_bg = 'Специален отпуск', name_uk = 'Спеціальна відпустка' WHERE code = 'SPL' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Άλλη απουσία', name_it = 'Altra assenza', name_bg = 'Друго отсъствие', name_uk = 'Інша відсутність' WHERE code = 'ABS' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Εκπαιδευτική άδεια', name_it = 'Permesso studio', name_bg = 'Учебен отпуск', name_uk = 'Навчальна відпустка' WHERE code = 'STUDY' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Πρόωρη αποχώρηση / καθυστερημένη προσέλευση', name_it = 'Uscita anticipata / ingresso posticipato', name_bg = 'Ранно тръгване / късно идване', name_uk = 'Ранній вихід / пізній прихід' WHERE code = 'EARLY' AND name_el IS NULL;
UPDATE hr_leave_types SET name_el = 'Παρακολούθηση σχολικής επίδοσης (ώρες)', name_it = 'Visita scolastica dei figli (ore)', name_bg = 'Посещение на училище (часове)', name_uk = 'Відвідування школи (години)' WHERE code = 'SCH' AND name_el IS NULL;
