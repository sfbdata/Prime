<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\JudicializarCasoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Judicializar um caso (SPEC §16 e `docs/specs/cobranca-judicializar-cria-pasta.md`).
 *
 * Um formulário só, dois modos:
 *
 * - `criar` (padrão): abre a pasta com `nomeCliente` (pré-preenchido com o responsável principal) e
 *   `nomeAcao` (pré-preenchido com `AÇÃO MONITÓRIA`), os dois editáveis;
 * - `vincular`: escolhe uma Pasta EXISTENTE do escritório (opção `pastas` = mapa label→id, montada
 *   pelo controller — idêntica no render e no POST para o ChoiceType casar).
 *
 * O modo é um `radio` (`expanded`) porque o segundo mora dentro de um `<details>` no template: assim
 * o caminho secundário funciona SEM JavaScript, que é o que um `<select>` escondido por JS não daria.
 * A obrigatoriedade de cada campo é condicional e vive no Input (`#[Assert\Callback]`), não aqui —
 * exigir tudo no Form tornaria o formulário impossível de enviar em qualquer um dos dois modos.
 *
 * `casoId` vem da rota. Exige o módulo `pastas`.
 */
final class JudicializarCasoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('modo', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'Criar uma pasta nova' => JudicializarCasoInput::MODO_CRIAR,
                    'Vincular uma pasta existente' => JudicializarCasoInput::MODO_VINCULAR,
                ],
                'expanded' => true,
                'multiple' => false,
                // ⚠️ NÃO passe `required: false` aqui: num ChoiceType `expanded` isso faz o Symfony
                // acrescentar um TERCEIRO rádio, o vazio — e o modal passa a oferecer uma opção que
                // não existe. O campo sempre tem valor (o Input nasce em `criar`), então o `required`
                // padrão é o certo.
                'choice_translation_domain' => false,
                // A classe cai no CONTAINER dos rádios (é o que `widget_container_attributes` faz
                // num ChoiceType `expanded`), e é ela que o `cobrancas.css` ancora para afastar as
                // duas opções. Sem uma classe nossa, o CSS teria de contar os `div`s que o tema de
                // formulário gera — que são DOIS acima dos inputs e mudam se o tema mudar.
                'attr' => ['class' => 'cob-judicializar-opcoes'],
            ])
            // SOMENTE-LEITURA desde 02/09: quem decide o nome e a ação é o caso, nos dois caminhos
            // (ver JudicializarCasoUseCase::normalizarPastaJudicial). Os campos ficam na tela para o
            // gestor CONFERIR o que será gravado — campo que aceita digitação e descarta o valor é
            // pior que campo ausente. `readonly` e não `disabled`: disabled não envia o valor e o
            // formulário passaria a exibir vazio ao reabrir com erro.
            ->add('nomeCliente', TextType::class, [
                'label' => 'Nome do cliente',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255, 'autocomplete' => 'off', 'readonly' => true],
            ])
            ->add('nomeAcao', TextType::class, [
                'label' => 'Ação',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255, 'autocomplete' => 'off', 'readonly' => true],
            ])
            ->add('pastaId', ChoiceType::class, [
                'label' => 'Pasta judicial',
                'choices' => $options['pastas'],
                'placeholder' => 'Selecione a pasta',
                'required' => false,
                'choice_translation_domain' => false,
                'attr' => ['class' => 'form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => JudicializarCasoInput::class,
            'pastas' => [],
        ]);
        $resolver->setAllowedTypes('pastas', 'array');
    }
}
