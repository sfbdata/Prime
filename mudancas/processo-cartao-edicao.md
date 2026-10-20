---
tipo: novidade
---
## Changelog
- Aba Processo: "Editar dados do processo" no próprio cartão (classe, assuntos, jurisdição, órgão julgador, última distribuição, valor da causa e as flags Sim/Não/Não informado de segredo de justiça, juízo 100% digital, justiça gratuita, tutela/liminar e prioridade). O valor manual prevalece sobre o do Datajud, com o selo "manual"; as flags "Sim" viram marcas no cartão; a linha de fonte diz que a integração com o PJe não está configurada. Colunas `*_manual` no `processo` (o Datajud não as sobrescreve) e registro no `audit_log`.
- Segredo de justiça marcado como "Sim" no cartão bloqueia a IA e o link externo do Push, como o sigilo informado pelo tribunal.

## Para o cliente
- Agora dá para completar os dados do processo direto no cartão, e as marcas (justiça gratuita, tutela, prioridade, segredo, 100% digital) aparecem em destaque.
