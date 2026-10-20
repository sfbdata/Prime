-- Dados de referência que o sistema exige e que só as migrations gravam.
--
-- O job "E-mails institucionais" (.github/workflows/ci.yml) monta o banco com `doctrine:schema:create`, sem
-- migrations (a cadeia antiga pressupõe dados de dump). Sem estas linhas, convidar alguém dá 500:
-- `PlanoRepository::free()` acusa "O plano Free não está cadastrado".
--
-- ACOMPANHE A MIGRATION: os valores vêm da app/migrations/Version20261019190000.php (planos do desenho 08,
-- decisão do dono D5 de 19/10/2026). Migration nova que mude ou acrescente dado de referência muda aqui também.

INSERT INTO contribua_plano (codigo, nome, cota_gb, preco_centavos, descricao, ordem, limite_usuarios, criado_em) VALUES
    ('free', 'Free', 10, 0, 'Todos os recursos do BlueJus, sem custo', 1, 3, (NOW() AT TIME ZONE 'America/Sao_Paulo')),
    ('essencial', 'Essencial', 20, 1990, 'Advogado autônomo ou escritório pequeno', 2, 8, (NOW() AT TIME ZONE 'America/Sao_Paulo')),
    ('profissional', 'Profissional', 50, 3990, 'Equipes que anexam processos e documentos todos os dias', 3, 15, (NOW() AT TIME ZONE 'America/Sao_Paulo')),
    ('avancado', 'Avançado', 100, 6990, 'Alto volume de processos e digitalizações', 4, NULL, (NOW() AT TIME ZONE 'America/Sao_Paulo'));

INSERT INTO contribua_configuracao (id) VALUES (1);
