<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * Cenário (categoria) da assembleia, como o assistente do desenho 18 (bj-voto-cenarios.js): o tipo de entidade que
 * promove a votação. Define só os RÓTULOS: o da entidade, as duas linhas de assinatura dos documentos (a1 e a2), o
 * aviso do passo 1, o ícone e o peso inicial sugerido. Não muda regra de apuração nem a cédula, por isso fica fora do
 * hash da configuração publicada. Padrão: condomínio (o comportamento de antes).
 */
enum CenarioDaAssembleia: string
{
    case Condominio = 'condominio';
    case Classe = 'classe';
    case Associacao = 'associacao';
    case Sindicato = 'sindicato';
    case Cooperativa = 'cooperativa';
    case Sociedade = 'sociedade';
    case Ensino = 'ensino';
    case Consulta = 'consulta';
    case Livre = 'livre';

    public function rotulo(): string
    {
        return match ($this) {
            self::Condominio => 'Assembleia de condomínio',
            self::Classe => 'Entidade de classe ou conselho',
            self::Associacao => 'Associação ou clube',
            self::Sindicato => 'Sindicato',
            self::Cooperativa => 'Cooperativa',
            self::Sociedade => 'Sócios ou acionistas',
            self::Ensino => 'Instituição de ensino',
            self::Consulta => 'Consulta interna',
            self::Livre => 'Personalizado',
        };
    }

    /** Rótulo do campo do nome da entidade (desenho: `rot.ent`). */
    public function entidade(): string
    {
        return match ($this) {
            self::Condominio => 'Condomínio',
            self::Associacao => 'Associação',
            self::Sindicato => 'Sindicato',
            self::Cooperativa => 'Cooperativa',
            self::Sociedade => 'Sociedade',
            self::Ensino => 'Instituição',
            self::Consulta => 'Organização',
            self::Classe, self::Livre => 'Entidade',
        };
    }

    /** Primeira linha de assinatura dos documentos (desenho: `rot.a1`). */
    public function assinatura1(): string
    {
        return match ($this) {
            self::Classe, self::Sindicato => 'Presidência da comissão eleitoral',
            self::Sociedade => 'Presidência da reunião',
            self::Ensino, self::Livre => 'Presidência da comissão',
            self::Consulta => 'Responsável pela consulta',
            self::Condominio, self::Associacao, self::Cooperativa => 'Presidência da assembleia',
        };
    }

    /** Segunda linha de assinatura dos documentos (desenho: `rot.a2`). */
    public function assinatura2(): string
    {
        return match ($this) {
            self::Classe, self::Sindicato => 'Membro da comissão eleitoral',
            self::Sociedade => 'Secretaria da reunião',
            self::Ensino, self::Livre => 'Membro da comissão',
            self::Consulta => 'Auditoria',
            self::Condominio, self::Associacao, self::Cooperativa => 'Secretaria da assembleia',
        };
    }

    /** Aviso do passo 1 (desenho: `ajuda`). */
    public function ajuda(): string
    {
        return match ($this) {
            self::Condominio => 'Votos por fração ideal, salvo disposição diversa da convenção. Confira na convenção o quórum de cada pauta, a segunda convocação e quem está apto a votar.',
            self::Classe => 'Siga o regulamento eleitoral da entidade: registro de chapas, ordem de apresentação e lista de aptos.',
            self::Associacao => 'Confira no estatuto o quórum e a forma de votação.',
            self::Sindicato => 'Confira no estatuto as regras de chapa e de quórum.',
            self::Cooperativa => 'Confira no estatuto os quóruns e a representação.',
            self::Sociedade => 'Peso conforme quotas ou ações. Confira contrato social ou estatuto.',
            self::Ensino => 'Grêmios, centros acadêmicos e colegiados.',
            self::Consulta => 'Pesquisas e decisões internas.',
            self::Livre => 'Configure livremente.',
        };
    }

    /** Ícone Bootstrap Icons do botão da categoria (mapa `edCens` do desenho). */
    public function icone(): string
    {
        return match ($this) {
            self::Condominio => 'bi-building',
            self::Classe => 'bi-award',
            self::Associacao => 'bi-people',
            self::Sindicato => 'bi-megaphone',
            self::Cooperativa => 'bi-diagram-3',
            self::Sociedade => 'bi-briefcase',
            self::Ensino => 'bi-mortarboard',
            self::Consulta => 'bi-chat-square-text',
            self::Livre => 'bi-sliders',
        };
    }

    /** Peso inicial sugerido (desenho: `ponderacao`: "peso" vira fração ideal, "um" vira uma unidade, um voto). */
    public function pesoSugerido(): PesoDoVoto
    {
        return match ($this) {
            self::Condominio, self::Sociedade => PesoDoVoto::Fracao,
            default => PesoDoVoto::Unidade,
        };
    }
}
