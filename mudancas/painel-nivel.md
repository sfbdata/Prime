---
tipo: novidade
---
## Changelog
- Dashboard, painel BlueJus Intelligence: novo bloco "Aumentar o nível" (desenho 01 VS 25.10.26, dc L1284-1325). Quatro níveis (Manter, +10%, +20%, +35%) sobre a meta de pastas do mês: nova meta, ritmo por dia útil, viabilidade, resumo, meta por colaborador (pelas pastas que cada um já criou), marcos por semana útil e alavancas. Tudo por regras fixas sobre dado real, sem IA e sem migration.
- O bloco é só do usuário primário (quem define a meta) e só aparece com meta definida, no mês inteiro, sem filtro de responsável ou cargo e sem pasta oculta pelo cadeado.
- "Definir X como meta do mês" grava a meta pelo mesmo POST `/dashboard/meta-pastas` (1 a 9999, CSRF, módulo bi, 403 para quem não é primário).

## Para o cliente
- No painel BlueJus Intelligence, o primário escolhe quanto o escritório quer crescer e vê a nova meta de pastas do mês, quanto cada pessoa precisa criar e os marcos por semana. Um botão aplica a nova meta.
