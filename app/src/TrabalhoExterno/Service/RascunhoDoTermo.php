<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\DTO\DadosDoTermo;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;

/**
 * O pré-preenchimento do modal (`termoNovo`, desenho 06 de 25/10, l.1127), montado no servidor:
 * - escritório: a sede da Central (nome, CNPJ, endereço, foro e local = cidade); e-mail e telefone não existem no
 *   cadastro e saem em branco (linha para preencher);
 * - representante: quem está gerando (nome e OAB);
 * - voluntário: o currículo. CPF e RG saem sempre em branco: o currículo não os guarda;
 * - padrões do desenho: atividades, 20 h por semana, a modalidade do currículo (ou "regime híbrido"), início hoje, 6
 *   meses, aviso de 10 dias, sem ressarcimento;
 * - havendo termo anterior do currículo, os campos vêm dele (a cópia do "Novo termo"), com a data do instrumento de hoje.
 */
final class RascunhoDoTermo
{
    private const ATIVIDADES = 'pesquisa jurídica, apoio na elaboração de peças e pareceres sob supervisão, acompanhamento de audiências e atividades correlatas';

    /** @return array<string, string> as chaves de {@see DadosDoTermo::CAMPOS} */
    public function montar(?Tenant $sede, User $quemGera, Curriculo $cv, ?TermoDeVoluntariado $anterior, \DateTimeImmutable $agora): array
    {
        $hoje = $agora->setTimezone(new \DateTimeZone(HtmlDoTermoDeVoluntariado::FUSO))->format('Y-m-d');
        $campos = $this->doEscritorio($sede) + $this->doRepresentante($quemGera) + $this->doVoluntario($cv) + $this->condicoes($cv, $hoje);
        if ($anterior !== null) {
            $campos = array_replace($campos, $anterior->getCampos(), ['data' => $hoje]);
        }

        return array_replace(array_fill_keys(array_keys(DadosDoTermo::CAMPOS), ''), $campos);
    }

    /** @return array<string, string> */
    private function doEscritorio(?Tenant $sede): array
    {
        $cidade = (string) $sede?->getCidade();
        $uf = (string) $sede?->getEstado();

        return [
            'eNome' => (string) $sede?->getName(), 'eDoc' => (string) $sede?->getCnpj(), 'eEnd' => $this->endereco($sede),
            'eEmail' => '', 'eTel' => '', 'foro' => $cidade, 'cidade' => $cidade !== '' ? $cidade . '/' . $uf : '',
        ];
    }

    /** O endereço montado do cadastro do escritório, ou "Cidade/UF" sem logradouro (`e0.end || cid/uf`). */
    private function endereco(?Tenant $sede): string
    {
        if ($sede === null) {
            return '';
        }
        $local = implode('/', array_filter([(string) $sede->getCidade(), (string) $sede->getEstado()]));
        $cep = (string) $sede->getCep() !== '' ? 'CEP ' . $sede->getCep() : '';
        $rua = implode(', ', array_filter([(string) $sede->getLogradouro(), (string) $sede->getNumero(), (string) $sede->getComplemento(), (string) $sede->getBairro()]));

        return $rua === '' ? $local : implode(', ', array_filter([$rua, $local, $cep]));
    }

    /** @return array<string, string> */
    private function doRepresentante(User $quem): array
    {
        return ['resp' => (string) $quem->getFullName(), 'respOab' => (string) $quem->getOabNumero(), 'respOabUf' => (string) $quem->getOabUf(), 'orient' => ''];
    }

    /** @return array<string, string> */
    private function doVoluntario(Curriculo $cv): array
    {
        return [
            'vNome' => $cv->texto('nome'), 'vNac' => 'brasileiro(a)', 'vRg' => '', 'vCpf' => '',
            'vOab' => $cv->texto('oabNum'), 'vOabUf' => $cv->texto('oabUf'), 'vSit' => $cv->getSituacao()->value,
            'vInst' => $cv->texto('inst'), 'vSem' => $cv->texto('sem'), 'vEmail' => $cv->texto('email'),
            'vEnd' => implode('/', array_filter([$cv->texto('cidade'), $cv->texto('uf')])),
        ];
    }

    /** @return array<string, string> */
    private function condicoes(Curriculo $cv, string $hoje): array
    {
        return [
            'ativ' => self::ATIVIDADES, 'horas' => '20', 'modal' => $cv->texto('modalidade') !== '' ? mb_strtolower($cv->texto('modalidade')) : 'regime híbrido',
            'inicio' => $hoje, 'meses' => '6', 'aviso' => '10', 'ress' => 'nao', 'ressLim' => '', 'data' => $hoje,
        ];
    }
}
