"""追記DDLをPostgreSQL 14の単独実行モードで検証する。接続先DBは使用しない。"""
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
root=tempfile.mkdtemp(prefix='affiliation-migration-')
bin=Path('/usr/lib/postgresql/14/bin')
subprocess.run([str(bin/'initdb'),'-D',root+'/data','-A','trust','--no-locale','-E','UTF8'],check=True,capture_output=True,text=True)
sql=(Path(__file__).resolve().parents[2]/'WritingWorkManagementSystem-Document/03_SQL/整形前/create.sql').read_text().split('/* -------------------------------------------------------------------------\n * キャラ所属履歴：',1)[1]
sql=sql[sql.index('BEGIN;'):sql.index('\nCOMMIT;')+len('\nCOMMIT;')]
sql=re.sub(r'--[^\n]*','',sql)
fixture='''
CREATE TABLE OrganizationInfo (orgId bigint PRIMARY KEY, workId uuid, orgName text);
CREATE TABLE TeamInfo (teamId bigint PRIMARY KEY, workId uuid, teamName text);
CREATE TABLE ClassInfo (classId bigint PRIMARY KEY, workId uuid, className text);
CREATE TABLE RaceInfo (raceId bigint PRIMARY KEY, workId uuid, raceName text);
CREATE TABLE StageInfo (stageId bigint PRIMARY KEY, workId uuid, stageName text);
CREATE TABLE CharacterInfo (charaId uuid PRIMARY KEY, workId uuid, orgId bigint, teamId bigint, classId bigint, raceId bigint, birthplaceId bigint,
 CONSTRAINT fk_chara_org FOREIGN KEY(orgId) REFERENCES OrganizationInfo(orgId) ON DELETE SET NULL,
 CONSTRAINT fk_chara_team FOREIGN KEY(teamId) REFERENCES TeamInfo(teamId) ON DELETE SET NULL,
 CONSTRAINT fk_chara_class FOREIGN KEY(classId) REFERENCES ClassInfo(classId) ON DELETE SET NULL);
INSERT INTO OrganizationInfo VALUES (1,'00000000-0000-0000-0000-000000000001','移行する組織');
INSERT INTO TeamInfo VALUES (2,'00000000-0000-0000-0000-000000000001','移行するチーム');
INSERT INTO ClassInfo VALUES (3,'00000000-0000-0000-0000-000000000001','移行する階級');
INSERT INTO CharacterInfo VALUES ('00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000001',1,2,3,NULL,NULL),
 ('00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000001',NULL,NULL,NULL,NULL,NULL);
'''
checks='''
DO $$ BEGIN
 IF (SELECT affiliations::jsonb #>> '{Affiliations,0,OrgName}' FROM CharacterInfo WHERE orgId=1) IS DISTINCT FROM '移行する組織' THEN RAISE EXCEPTION 'name migration'; END IF;
 IF (SELECT affiliations::jsonb #>> '{Affiliations,0,JoinedDate}' FROM CharacterInfo WHERE orgId=1) IS DISTINCT FROM '' THEN RAISE EXCEPTION 'invented date'; END IF;
 IF (SELECT jsonb_array_length(affiliations::jsonb->'Affiliations') FROM CharacterInfo WHERE orgId IS NULL) IS DISTINCT FROM 0 THEN RAISE EXCEPTION 'empty history'; END IF;
 IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname IN ('fk_chara_org','fk_chara_team','fk_chara_class')) THEN RAISE EXCEPTION 'foreign keys'; END IF;
END $$;
DELETE FROM OrganizationInfo;
DO $$ BEGIN
 IF (SELECT orgName FROM v_character_detail WHERE orgId=1) IS DISTINCT FROM '移行する組織' THEN RAISE EXCEPTION 'deleted name lost'; END IF;
END $$;
SELECT 'AFFILIATION_MIGRATION_OK';
'''
try:
    result=subprocess.run([str(bin/'postgres'),'--single','-D',root+'/data','postgres'],input=(fixture+sql+sql+checks).replace('\n',' ')+'\n',capture_output=True,text=True)
    if result.returncode or re.search(r'\b(?:ERROR|FATAL|PANIC):',result.stderr):
        print((result.stdout+result.stderr)[-4000:])
        raise SystemExit(1)
    assert 'AFFILIATION_MIGRATION_OK' in result.stdout
    print('PostgreSQL 14 migration passed (existing data, repeat execution, JSON/view, dropped FKs, retained names after deletion).')
finally:
    shutil.rmtree(root)
