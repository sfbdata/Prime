# Perfis 1.2 · VS 25.10.26: Metas de divulgação, mapa, configurações e scripts

Risco MÉDIO (Master, configuração global; sem identidade User/Tenant). Desenho:
`docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html`, comparado com o VS 21.10.26 (já implementado).

## Escopo (pedido do Coordenador, 26/10/2026)

| PR | Entrega | Desenho (linhas) | Migration |
|---|---|---|---|
| P1 | Botão "Indicar o BlueJus" no cabeçalho (`bj-compartilhar.js`), antes do sino, abre a janela de indicação que já existe | `bj-compartilhar.js` | não |
| P2 | Configurações globais (`secConfig`): 5 grupos, Restaurar padrão, Descartar, Salvar, status, auditoria | L280-284, L955-959 | sim (chave/valor) |
| P3 | Metas de divulgação (Visão geral, depois do mapa): mercado, fases, tabela por UF/cidade, cores de destaque, mapa com realce | L158-170, L1000-1100 | sim (config, meta por UF/cidade, destaque) |
| P4 | Modos do mapa: `quem=ind` (Indicações enviadas, Contas abertas, Recompensas pagas) e convênio por UF (painel recolhível + rótulo na conta) | L116, L176-180, L654-657, L1062-1100 | sim (convênio por UF) |

`bj-monitor.js` só alimenta a seção Desempenho (fora do escopo; o real usa `GET /master/saude`). Entra junto com ela.

## Fora do escopo, a decidir com o Coordenador

Seções novas do desenho que não existem no sistema e são grandes: Service Desk · saldos (`sd`), Recompensas (`recomp`),
Custos (`custos`), Desempenho (`desemp`), reforma de Planos e limites (faixa OAB, 4 KPIs, 4 planos).
`recomp` alimenta o dado "Recompensas pagas (R$)" do mapa (P4): sem ela, essa opção sai com zero até existir o extrato.

## Regras

- O desenho manda; componentes do desenho, não do AdminLTE. Persistência em tabelas (o desenho usa localStorage só por ser protótipo).
- Só o Master acessa (rotas `master_*`, mesma guarda do painel). Escrita auditada ("Configurações globais alteradas", etc.).
- Cada PR começa pelo UseCase e pelos testes. Sem travessão em texto novo.
- Arquivos quentes: `base.html.twig` (P1), `messages pt_BR` (chaves no fim do bloco), migrations (número pedido ao Coordenador).

## Ordem e dependências

P1 → P2 → P3 → P4. P2 e P3 independentes (migrations distintas, uma por vez). P4 depende da base de Indicações (já existe).

## Pendências de negócio (só perguntar se bloquear)

- P2: quais chaves o sistema lê de fato hoje (fuso, idioma, sessão, tentativas). Configuração que nada consome não pode enganar: ligar só o que tem efeito, o resto grava e fica marcado como "valor de referência" apenas se o dono aprovar.
- P3: fonte do "mercado" (1.510.000 no desenho) e de "advogados por UF" (hoje vem do mapa-dados).
