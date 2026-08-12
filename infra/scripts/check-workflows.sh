#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Duplicate keys in a GitHub Actions workflow.
#
# This exists because of a specific failure: a duplicated `if-no-files-found:`
# in one step made the workflow file invalid, and GitHub responded by running
# *no jobs at all* on two consecutive pushes. Nothing went red in the usual
# sense — the suites, the gates, the compliance checks simply never executed.
#
# It cannot be caught by a job inside that same workflow, because an invalid
# workflow does not start any. So it has to run before the push.
#
# Every YAML parser reached for at the time — Ruby's Psych, PyYAML, js-yaml —
# loads a duplicate key happily and keeps the last value. Actions does not.
# Hence walking the parse tree rather than trusting a successful load.
set -euo pipefail

cd "$(dirname "$0")/../.."

if ! command -v ruby >/dev/null 2>&1; then
    echo "SKIP: ruby not available, cannot check workflow files"
    exit 0
fi

status=0

for file in .github/workflows/*.yml .github/workflows/*.yaml; do
    [ -e "$file" ] || continue

    if ! ruby -ryaml -e '
        def walk(node, path, file)
            found = 0
            if node.is_a?(Psych::Nodes::Mapping)
                seen = {}
                node.children.each_slice(2) do |key, value|
                    name = key.respond_to?(:value) ? key.value : key.to_s
                    if seen.key?(name)
                        warn "  #{file}:#{key.start_line + 1}: duplicate key \"#{name}\" under #{path}"
                        found += 1
                    end
                    seen[name] = true
                    found += walk(value, "#{path}/#{name}", file)
                end
            elsif node.respond_to?(:children) && node.children
                node.children.each_with_index do |child, i|
                    found += walk(child, "#{path}[#{i}]", file)
                end
            end
            found
        end

        file = ARGV[0]
        doc = Psych.parse_file(file)
        exit(walk(doc, "", file).zero? ? 0 : 1)
    ' "$file"; then
        status=1
    fi
done

if [ "$status" -ne 0 ]; then
    echo "FAIL: duplicate keys would make the workflow invalid — Actions will run no jobs"
    exit 1
fi

echo "OK: workflow files carry no duplicate keys"
