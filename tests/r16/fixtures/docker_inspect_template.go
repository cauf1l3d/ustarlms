// Test helper: evaluate the collector's actual template with Docker inspect's
// raw fallback's strict Go text/template semantics. No daemon or network calls.
package main

import (
	"encoding/json"
	"fmt"
	"os"
	"text/template"
)

func main() {
	var request struct {
		Format string
		Data   map[string]any
	}
	decoder := json.NewDecoder(os.Stdin)
	decoder.UseNumber()
	if err := decoder.Decode(&request); err != nil {
		fmt.Fprintln(os.Stderr, "invalid fixture")
		os.Exit(2)
	}
	functions := template.FuncMap{"json": func(value any) (string, error) {
		encoded, err := json.Marshal(value)
		return string(encoded), err
	}}
	parsed, err := template.New("inspect").Funcs(functions).Option("missingkey=error").Parse(request.Format)
	if err == nil {
		err = parsed.Execute(os.Stdout, request.Data)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "template evaluation failed")
		os.Exit(1)
	}
}
