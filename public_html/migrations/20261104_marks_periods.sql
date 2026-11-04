INSERT INTO `{{prefix}}assessment_periods` (academic_year_id,code,name,mark_step)
SELECT y.id,p.code,p.name,p.mark_step
FROM `{{prefix}}academic_years` y
CROSS JOIN (
    SELECT 'term1' AS code,'Α΄ τετράμηνο' AS name,1.0 AS mark_step
    UNION ALL SELECT 'term2','Β΄ τετράμηνο',1.0
    UNION ALL SELECT 'written','Γραπτά',0.2
) p
WHERE y.is_active=1
ON DUPLICATE KEY UPDATE id=`{{prefix}}assessment_periods`.id;
