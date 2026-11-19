<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Enum\TipoDeFerramenta;
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\DespachoDasFerramentas;
use App\Conector\Ferramenta\FerramentaDoConector;

/**
 * O que a página "Como conectar o Claude ou o ChatGPT" (Meu Perfil; spec mcp-remoto-oauth, 13.1 linha do PR 15) lista em
 * "O que dá para pedir": as ferramentas REGISTRADAS no servidor, na ordem do catálogo fechado, separadas em consultar e
 * gravar. Sai do registro, e não de uma lista à parte, para a página nunca prometer ferramenta que ainda não existe nem
 * esquecer a que acabou de entrar. A `confirmar_acao` fica fora da lista: a página a explica em "Gravar com segurança".
 */
final class GuiaDoConector
{
    public function __construct(
        private readonly DespachoDasFerramentas $despacho,
    ) {
    }

    /** @return array{leitura: list<string>, gravacao: list<string>} os nomes das ferramentas, na ordem do catálogo */
    public function ferramentas(): array
    {
        $registradas = array_map(static fn (FerramentaDoConector $f): string => $f->nome(), $this->despacho->registradas());
        $grupos = ['leitura' => [], 'gravacao' => []];
        foreach (CatalogoFechadoDoConector::FERRAMENTAS as $nome => $tipo) {
            if (!\in_array($nome, $registradas, true)) {
                continue;
            }
            match ($tipo) {
                TipoDeFerramenta::Leitura => $grupos['leitura'][] = $nome,
                TipoDeFerramenta::Gravacao => $grupos['gravacao'][] = $nome,
                TipoDeFerramenta::Confirmacao => null,
            };
        }

        return $grupos;
    }
}
