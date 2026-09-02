<?php
/**
 * Generate abilities.md (or abilities.json) from the plugin registry.
 *
 * DEV TOOLING. Lives outside the plugin folder, never shipped in the plugin
 * zip. Only reads registry.php (via lib-abilities.php).
 *
 * Usage:
 *   php bin/generate-abilities-md.php          # prints abilities.md to stdout
 *   php bin/generate-abilities-md.php --json    # prints abilities.json to stdout
 *
 * @package WSP_MCP
 */

require __DIR__ . '/lib-abilities.php';

$abilities       = wsp_abilities_all();
$core_groups     = wsp_abilities_core_groups();
$plugin_sections = wsp_abilities_plugin_sections();
$totals          = wsp_abilities_totals();

// --- Bucket abilities by group (insertion order preserved) ------------------
$by_group = array();
foreach ( $abilities as $key => $a ) {
	$by_group[ $a['group'] ][ $key ] = $a;
}

// --- JSON mode --------------------------------------------------------------
if ( in_array( '--json', $argv, true ) ) {
	$rows = array();
	foreach ( $abilities as $key => $a ) {
		$is_core = in_array( $a['group'], $core_groups, true );
		$rows[]  = array(
			'id'          => $key,
			'name'        => $a['label'],
			'description' => $a['description'],
			'group'       => $a['group'],
			'access'      => $a['access'],
			'default'     => ! empty( $a['default'] ) ? 'on' : 'off',
			'requires'    => $is_core ? null : ( isset( $plugin_sections[ $a['group'] ] ) ? $a['group'] : null ),
		);
	}
	echo json_encode(
		array( 'totals' => $totals, 'abilities' => $rows ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";
	exit( 0 );
}

// --- Markdown render helper -------------------------------------------------
function wsp_render_table( array $rows ) {
	$out  = "| Ability ID | Name | Description | Access | Default |\n";
	$out .= "|---|---|---|---|---|\n";
	foreach ( $rows as $key => $a ) {
		$default = ! empty( $a['default'] ) ? 'on' : 'off';
		$out    .= sprintf(
			"| `%s` | %s | %s | %s | %s |\n",
			$key,
			$a['label'],
			$a['description'],
			$a['access'],
			$default
		);
	}
	return $out;
}

// --- Emit Markdown ----------------------------------------------------------
$md  = "# Abilities (Tool Registry)\n\n";
$md .= "This file is the **source of truth** for the abilities shown on the\n";
$md .= "[Abilities Directory](https://freewordpressmcp.com/abilities-directory) page (`abilities-directory.html`).\n\n";
$md .= "Each ability is one MCP tool the **WSP MCP – AI Agents Connector** plugin exposes to a connected AI agent.\n";
$md .= "The list mirrors the plugin registry at\n";
$md .= "`wsp-mcp-ai-agents-connector/includes/registry.php` in\n";
$md .= "[bilalnaseer/wsp-wordpress-mcp](https://github.com/bilalnaseer/wsp-wordpress-mcp).\n\n";
$md .= "> **Generated file — do not edit by hand.** Produced by `bin/generate-abilities-md.php`; CI regenerates it whenever `registry.php` changes.\n\n";
$md .= "**When this file changes, `abilities-directory.html` and `sitemap.xml` are updated automatically by the sync workflow.**\n\n";
$md .= "## Legend\n\n";
$md .= "- **Access** — `read` (safe, list/fetch only) or `write` (creates, updates, or deletes site data).\n";
$md .= "- **Default** — `on` means enabled out of the box; everything else is off until the user turns it on in the WordPress dashboard.\n";
$md .= "- **Requires** — core groups are always available; plugin groups appear only when the named plugin is active.\n\n";
$md .= "## Totals\n\n";
$md .= "- **{$totals['total']}** total abilities\n";
$md .= "- **{$totals['core']}** core (always available)\n";
$md .= "- **{$totals['read']}** read · **{$totals['write']}** write\n\n";
$md .= "---\n\n";
$md .= "## Core — always available\n\n";

foreach ( $core_groups as $group ) {
	if ( empty( $by_group[ $group ] ) ) {
		continue;
	}
	$md .= "### {$group}\n\n";
	$md .= wsp_render_table( $by_group[ $group ] );
	$md .= "\n";
}

foreach ( $plugin_sections as $group => $requires ) {
	if ( empty( $by_group[ $group ] ) ) {
		continue;
	}
	$md .= "---\n\n";
	$md .= "## {$group} — {$requires}\n\n";
	$md .= wsp_render_table( $by_group[ $group ] );
	$md .= "\n";
}

echo rtrim( $md ) . "\n";
